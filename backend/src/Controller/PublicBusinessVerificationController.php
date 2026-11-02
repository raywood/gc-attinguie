<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Business;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PublicBusinessVerificationController extends AbstractController
{
    #[Route('/api/public/businesses/{token}', name: 'api_business_public_verify', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function verify(string $token, EntityManagerInterface $em): JsonResponse
    {
        $business = $em->getRepository(Business::class)->findOneBy(['qrPublicToken' => $token]);
        if (!$business instanceof Business) return $this->noStore(['valid' => false, 'status' => 'unknown'], 404);
        if ($business->getQrRevokedAt() !== null) return $this->noStore(['valid' => false, 'status' => 'revoked'], 410);
        return $this->noStore([
            'valid' => true,
            'reference' => sprintf('GC-ATT-%06d', $business->getId()),
            'name' => $business->getName(),
            'activity' => $business->getActivityType()->getLabel(),
            'district' => $business->getDistrict(),
            'status' => $business->getStatus()->value,
            // Ni adresse détaillée, ni gérant, ni ID interne, ni données financières.
        ]);
    }

    private function noStore(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
