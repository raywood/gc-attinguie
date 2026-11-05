<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\ActivityTariff;
use App\Entity\ActivityType;
use App\Service\AdminCatalogInput;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/catalog/tariffs')]
#[IsGranted('ROLE_ADMIN')]
final class AdminTariffCatalogController extends AbstractController
{
    private const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly AdminCatalogInput $input,
    ) {}

    #[Route('', name: 'gc_admin_tariffs_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $rows = $this->em->getRepository(ActivityTariff::class)->findBy([], ['id' => 'ASC']);
        return $this->json([
            'items' => array_map(fn (ActivityTariff $t) => $this->serialize($t), $rows),
            'csrf' => $this->input->token(),
            'note' => 'Seule la périodicité mensuelle alimente actuellement la facturation automatique.',
        ]);
    }

    #[Route('', name: 'gc_admin_tariffs_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->input->body($request);
        if ($data instanceof JsonResponse) return $data;
        if (array_diff(array_keys($data), ['activityTypeId','period','amount','active'])
            || !isset($data['activityTypeId'],$data['period'],$data['amount'])) {
            return $this->json(['error' => 'Champs requis : activityTypeId, period, amount.'], 422);
        }
        $id = filter_var($data['activityTypeId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $period = $data['period'];
        if ($id === false || !is_string($period) || !in_array($period, self::PERIODS, true)) {
            return $this->json(['error' => 'Activité ou périodicité invalide.'], 422);
        }
        $activity = $this->em->find(ActivityType::class, $id);
        if (!$activity) return $this->json(['error' => 'Activité introuvable.'], 404);
        $amount = $this->money($data['amount']);
        if ($amount === null) return $this->json(['error' => 'Montant XOF invalide (maximum 2 décimales).'], 422);
        $active = $data['active'] ?? true;
        if (!is_bool($active)) return $this->json(['error' => 'Le champ active doit être booléen.'], 422);
        if ($this->em->getRepository(ActivityTariff::class)->findOneBy(['activityType' => $activity, 'period' => $period])) {
            return $this->json(['error' => 'Tarif déjà défini pour cette activité et cette périodicité : modifiez-le.'], 409);
        }
        $tariff = new ActivityTariff($activity, $period, $amount, $active);
        $this->em->persist($tariff);
        $this->syncMonthlyFee($tariff);
        $this->em->flush();
        return $this->json($this->serialize($tariff), 201);
    }

    #[Route('/{id<\d+>}', name: 'gc_admin_tariffs_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $tariff = $this->em->find(ActivityTariff::class, $id);
        if (!$tariff) return $this->json(['error' => 'Tarif introuvable.'], 404);
        $data = $this->input->body($request);
        if ($data instanceof JsonResponse) return $data;
        if (array_diff(array_keys($data), ['amount','active']) || !array_key_exists('amount', $data)) {
            return $this->json(['error' => 'Champs autorisés : amount et active.'], 422);
        }
        $amount = $this->money($data['amount']);
        if ($amount === null) return $this->json(['error' => 'Montant XOF invalide.'], 422);
        $active = $data['active'] ?? $tariff->isActive();
        if (!is_bool($active)) return $this->json(['error' => 'Le champ active doit être booléen.'], 422);
        $tariff->setAmount($amount)->setActive($active);
        $this->syncMonthlyFee($tariff);
        $this->em->flush();
        return $this->json($this->serialize($tariff));
    }

    #[Route('/{id<\d+>}', name: 'gc_admin_tariffs_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $tariff = $this->em->find(ActivityTariff::class, $id);
        if (!$tariff) return $this->json(['error' => 'Tarif introuvable.'], 404);
        $body = $this->input->body($request);
        if ($body instanceof JsonResponse) return $body;
        if ($tariff->getPeriod() === 'monthly') {
            $count = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM businesses WHERE activity_type_id = :id',
                ['id' => $tariff->getActivityType()->getId()]
            );
            if ($count > 0) {
                return $this->json(['error' => 'Tarif mensuel utilisé par des commerces : désactivez-le ou modifiez-le, sans le supprimer.'], 409);
            }
            $tariff->getActivityType()->setMonthlyFee('0.00');
        }
        $this->em->remove($tariff);
        $this->em->flush();
        return $this->json(['deleted' => true]);
    }

    /** N'altère jamais les factures passées : seules les futures générations consultent monthlyFee. */
    private function syncMonthlyFee(ActivityTariff $tariff): void
    {
        if ($tariff->getPeriod() !== 'monthly') return;
        $tariff->getActivityType()->setMonthlyFee($tariff->isActive() ? $tariff->getAmount() : '0.00');
    }

    private function money(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        $raw = trim((string) $value);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $raw)) return null;
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        return (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, 2, '0');
    }

    private function serialize(ActivityTariff $tariff): array
    {
        $activity = $tariff->getActivityType();
        return [
            'id' => $tariff->getId(),
            'activityTypeId' => $activity->getId(), 'activityLabel' => $activity->getLabel(),
            'period' => $tariff->getPeriod(), 'amount' => $tariff->getAmount(),
            'currency' => $tariff->getCurrency(), 'active' => $tariff->isActive(),
        ];
    }
}
