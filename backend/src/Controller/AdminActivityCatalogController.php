<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\ActivityType;
use App\Service\AdminCatalogInput;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/catalog/activities')]
#[IsGranted('ROLE_ADMIN')]
final class AdminActivityCatalogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly AdminCatalogInput $input,
    ) {}

    #[Route('', name: 'gc_admin_activities_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $rows = $this->em->getRepository(ActivityType::class)->findBy([], ['label' => 'ASC']);
        return $this->json([
            'items' => array_map(fn (ActivityType $a) => $this->serialize($a), $rows),
            'csrf' => $this->input->token(),
        ]);
    }

    #[Route('', name: 'gc_admin_activities_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->input->body($request);
        if ($data instanceof JsonResponse) return $data;
        $parsed = $this->validate($data, null);
        if ($parsed instanceof JsonResponse) return $parsed;
        [$code, $label] = $parsed;
        $activity = (new ActivityType())->setCode($code)->setLabel($label)->setMonthlyFee('0.00')->setCurrency('XOF');
        $this->em->persist($activity);
        $this->em->flush();
        return $this->json($this->serialize($activity), 201);
    }

    #[Route('/{id<\d+>}', name: 'gc_admin_activities_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $activity = $this->em->find(ActivityType::class, $id);
        if ($activity === null) return $this->json(['error' => 'Activité introuvable.'], 404);
        $data = $this->input->body($request);
        if ($data instanceof JsonResponse) return $data;
        $parsed = $this->validate($data, $activity);
        if ($parsed instanceof JsonResponse) return $parsed;
        [$code, $label] = $parsed;
        if ($code !== $activity->getCode() && ($this->businessCount($id) > 0 || $this->tariffCount($id) > 0)) {
            return $this->json(['error' => 'Code immuable dès que cette activité possède des commerces ou des tarifs.'], 409);
        }
        $activity->setCode($code)->setLabel($label);
        $this->em->flush();
        return $this->json($this->serialize($activity));
    }

    #[Route('/{id<\d+>}', name: 'gc_admin_activities_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $activity = $this->em->find(ActivityType::class, $id);
        if ($activity === null) return $this->json(['error' => 'Activité introuvable.'], 404);
        $check = $this->input->body($request);
        if ($check instanceof JsonResponse) return $check;
        if ($this->businessCount($id) > 0) {
            return $this->json(['error' => 'Suppression impossible : des commerces utilisent cette activité.'], 409);
        }
        if ($this->tariffCount($id) > 0) {
            return $this->json(['error' => 'Supprimez d’abord les tarifs de cette activité.'], 409);
        }
        $this->em->remove($activity);
        $this->em->flush();
        return $this->json(['deleted' => true]);
    }

    /** @return array{string,string}|JsonResponse */
    private function validate(array $data, ?ActivityType $current): array|JsonResponse
    {
        if (array_diff(array_keys($data), ['code','label']) || !isset($data['code'],$data['label']) || !is_string($data['code']) || !is_string($data['label'])) {
            return $this->json(['error' => 'Champs requis : code et label.'], 422);
        }
        $rawCode = trim($data['code']);
        $code = $current !== null && $rawCode === $current->getCode() ? $rawCode : strtoupper($rawCode);
        $label = trim($data['label']);
        if (($current === null || $code !== $current->getCode()) && !preg_match('/^[A-Z][A-Z0-9_]{1,49}$/D', $code)) {
            return $this->json(['error' => 'Code : 2 à 50 caractères, lettres majuscules, chiffres ou underscore.'], 422);
        }
        if (mb_strlen($label) < 2 || mb_strlen($label) > 120) {
            return $this->json(['error' => 'Libellé : entre 2 et 120 caractères.'], 422);
        }
        // Ne pas laisser créer BAR si la base contient déjà le code historique bar.
        $existingId = $this->db->fetchOne('SELECT id FROM activity_types WHERE LOWER(code) = LOWER(:code) LIMIT 1', ['code' => $code]);
        if ($existingId !== false && (int) $existingId !== $current?->getId()) {
            return $this->json(['error' => 'Ce code d’activité existe déjà.'], 409);
        }
        return [$code, $label];
    }

    private function businessCount(int $id): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM businesses WHERE activity_type_id = :id', ['id' => $id]);
    }
    private function tariffCount(int $id): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM activity_tariffs WHERE activity_type_id = :id', ['id' => $id]);
    }
    private function serialize(ActivityType $activity): array
    {
        $id = (int) $activity->getId();
        return [
            'id' => $id, 'code' => $activity->getCode(), 'label' => $activity->getLabel(),
            'monthlyFee' => $activity->getMonthlyFee(), 'currency' => $activity->getCurrency(),
            'businessCount' => $this->businessCount($id), 'tariffCount' => $this->tariffCount($id),
        ];
    }
}
