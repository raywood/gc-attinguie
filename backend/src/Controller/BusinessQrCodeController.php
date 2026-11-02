<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Business;
use App\Service\BusinessCertificatePdfService;
use App\Service\BusinessQrCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Accès privé : le subscriber municipal vérifie également commerces.read. */
#[Route('/api/businesses')]
#[IsGranted('commerces.read')]
final class BusinessQrCodeController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** Résolution réservée au personnel authentifié. L'ID interne ne figure jamais dans la réponse publique. */
    #[Route('/resolve-qr/{token}', name: 'business_qr_resolve', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function resolve(string $token): JsonResponse
    {
        $business = $this->em->getRepository(Business::class)->findOneBy(['qrPublicToken' => $token]);
        if (!$business || $business->getQrRevokedAt() !== null) {
            return $this->json(['error' => 'QR Code introuvable ou révoqué.'], 404);
        }
        $response = $this->json(['businessId' => $business->getId()]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/{id<\d+>}/qrcode', name: 'business_qr_info', methods: ['GET'])]
    public function info(int $id, BusinessQrCodeService $service): JsonResponse
    {
        $business = $this->findBusiness($id);
        return $this->json([
            'reference' => $service->reference($business),
            'verificationUrl' => $service->verificationUrl($business),
            'imageDataUri' => $service->dataUri($business),
            'version' => $business->getQrVersion(),
            'status' => $business->getStatus()->value,
        ]);
    }

    #[Route('/{id<\d+>}/qrcode/download', name: 'business_qr_download', methods: ['GET'])]
    public function download(int $id, BusinessQrCodeService $service, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        $business = $this->findBusiness($id);
        $format = $request->query->get('format', 'png');
        if (!in_array($format, ['png', 'svg'], true)) {
            return $this->json(['error' => 'Format attendu : png ou svg.'], 422);
        }
        $content = $format === 'svg' ? $service->svg($business) : $service->png($business);
        $response = new Response($content, 200, [
            'Content-Type' => $format === 'svg' ? 'image/svg+xml' : 'image/png',
            'Content-Disposition' => 'attachment; filename="' . $service->reference($business) . '-qr.' . $format . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        return $this->privateResponse($response);
    }

    #[Route('/{id<\d+>}/certificate.pdf', name: 'business_qr_certificate', methods: ['GET'])]
    public function certificate(int $id, BusinessCertificatePdfService $pdf, BusinessQrCodeService $service): Response
    {
        $business = $this->findBusiness($id);
        return $this->privateResponse(new Response($pdf->render($business), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $service->reference($business) . '-identification.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function findBusiness(int $id): Business
    {
        $business = $this->em->find(Business::class, $id);
        if (!$business) throw $this->createNotFoundException('Commerce introuvable.');
        return $business;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
