<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Business;
use App\Entity\BusinessManagerTransferAudit;
use App\Entity\User;
use App\Service\BusinessManagerTransferService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/businesses/{id<\d+>}/manager-transfer')]
#[IsGranted('ROLE_ADMIN')]
final class BusinessManagerTransferController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'gc_business_manager_transfer', methods: ['POST'])]
    public function transfer(int $id, Request $request, BusinessManagerTransferService $service): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            return $this->json(['error' => 'Authentification requise.'], 401);
        }
        $business = $this->em->find(Business::class, $id);
        if ($business === null) {
            return $this->json(['error' => 'Commerce introuvable.'], 404);
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return $this->json(['error' => 'Content-Type application/json requis.'], 415);
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'JSON invalide.'], 400);
        }
        $values = [];
        foreach (['managerFirstName', 'managerLastName', 'managerEmail', 'managerPhone', 'reason'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                return $this->json(['error' => "Champ invalide : $key."], 422);
            }
            $values[$key] = trim($data[$key] ?? '');
        }
        $values['managerEmail'] = mb_strtolower($values['managerEmail']);
        $errors = [];
        foreach (['managerFirstName', 'managerLastName'] as $field) {
            if ($values[$field] === '' || mb_strlen($values[$field]) > 100) {
                $errors[$field] = 'Obligatoire, 100 caractères maximum.';
            }
        }
        if (!filter_var($values['managerEmail'], FILTER_VALIDATE_EMAIL)
            || mb_strlen($values['managerEmail']) > 180
            || preg_match('/\.(invalid|test)$/i', $values['managerEmail'])) {
            $errors['managerEmail'] = 'Adresse e-mail valide et non réservée obligatoire.';
        }
        if (mb_strlen($values['managerPhone']) > 30) $errors['managerPhone'] = '30 caractères maximum.';
        if (mb_strlen($values['reason']) < 10 || mb_strlen($values['reason']) > 500) {
            $errors['reason'] = 'Motif obligatoire, de 10 à 500 caractères.';
        }
        if (($data['confirmTransfer'] ?? false) !== true) {
            $errors['confirmTransfer'] = 'Le remplacement doit être confirmé explicitement.';
        }
        if ($errors !== []) return $this->json(['errors' => $errors], 422);
        try {
            $result = $service->transfer($business, $actor, $values);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Transfert concurrent ou accès existant : rechargez la fiche.'], 409);
        }
        $response = $this->json(['ok' => true, ...$result]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/history', name: 'gc_business_manager_transfer_history', methods: ['GET'])]
    public function history(int $id): JsonResponse
    {
        $business = $this->em->find(Business::class, $id);
        if ($business === null) return $this->json(['error' => 'Commerce introuvable.'], 404);
        $history = $this->em->getRepository(BusinessManagerTransferAudit::class)->findBy(
            ['business' => $business], ['changedAt' => 'DESC', 'id' => 'DESC'], 50
        );
        return $this->json(['items' => array_map(static fn (BusinessManagerTransferAudit $a): array => [
            'oldManager' => $a->getOldManagerName(),
            'newManager' => $a->getNewManagerName(),
            'oldEmail' => $a->getOldEmail(),
            'newEmail' => $a->getNewEmail(),
            'reason' => $a->getReason(),
            'changedAt' => $a->getChangedAt()->format(DATE_ATOM),
            'changedBy' => $a->getChangedByEmail(),
        ], $history)]);
    }
}
