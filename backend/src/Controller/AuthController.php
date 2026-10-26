<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\ActivityType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthController extends AbstractController
{
    /** La requête est traitée par le firewall (json_login) ; ce code ne s'exécute qu'en cas de succès. */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => 'Identifiants incorrects.'], 401);
        }

        return $this->json($this->userPayload($user));
    }

    /** Intercepté par le firewall (logout) : ne s'exécute jamais. */
    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Géré par le firewall.');
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->userPayload($user));
    }

    #[Route('/api/activity-types', name: 'api_activity_types', methods: ['GET'])]
    public function activityTypes(EntityManagerInterface $em): JsonResponse
    {
        $types = $em->getRepository(ActivityType::class)->findBy([], ['label' => 'ASC']);

        return $this->json(array_map(static fn (ActivityType $t) => [
            'id' => $t->getId(),
            'code' => $t->getCode(),
            'label' => $t->getLabel(),
            'monthlyFee' => $t->getMonthlyFee(),
            'currency' => $t->getCurrency(),
        ], $types));
    }

    /** @return array{email: string, roles: list<string>} */
    private function userPayload(User $user): array
    {
        return ['email' => $user->getUserIdentifier(), 'roles' => $user->getRoles()];
    }
}
