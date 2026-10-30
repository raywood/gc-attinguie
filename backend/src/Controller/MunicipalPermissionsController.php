<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use App\Security\MunicipalPermissionResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MunicipalPermissionsController extends AbstractController
{
    #[Route('/api/rbac/me', name: 'api_rbac_my_permissions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function me(MunicipalPermissionResolver $resolver): JsonResponse
    {
        $u = $this->getUser();
        if (!$u instanceof User || $u->getId() === null) throw $this->createAccessDeniedException();
        $grants = $resolver->forUserId($u->getId());
        if (!$grants['active']) throw $this->createAccessDeniedException();
        $response = $this->json(['roles' => $grants['roles'], 'permissions' => $grants['permissions'], 'isAdmin' => $grants['admin']]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
