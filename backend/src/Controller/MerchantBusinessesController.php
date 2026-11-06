<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\MerchantBusinessAccess;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/merchant/businesses')]
#[IsGranted('ROLE_GERANT')]
final class MerchantBusinessesController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'gc_merchant_businesses_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authentification requise.'], 401);
        $accesses = $this->em->getRepository(MerchantBusinessAccess::class)
            ->findBy(['user' => $user, 'revokedAt' => null]);
        return $this->json(['items' => array_map(static fn (MerchantBusinessAccess $a): array => [
            'id' => $a->getBusiness()->getId(),
            'name' => $a->getBusiness()->getName(),
            'activity' => $a->getBusiness()->getActivityType()->getLabel(),
            'status' => $a->getBusiness()->getStatus()->value,
        ], $accesses)]);
    }
}
