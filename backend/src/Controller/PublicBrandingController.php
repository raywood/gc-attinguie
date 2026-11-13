<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppBranding;
use App\Service\AppLogoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Identité visuelle publique ; aucune donnée d'utilisateur ni jeton CSRF. */
final class PublicBrandingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppLogoStorage $logos,
    ) {}

    #[Route('/api/public/branding', name: 'gc_public_branding', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $brand = $this->em->find(AppBranding::class, 1) ?? new AppBranding();
        $response = $this->json(self::payload($brand));
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        return $response;
    }

    #[Route('/api/public/branding/logo', name: 'gc_public_branding_logo', methods: ['GET'])]
    public function logo(): Response
    {
        $brand = $this->em->find(AppBranding::class, 1);
        $filename = $brand?->getLogoFilename();
        if ($filename === null) {
            return $this->redirect('/gc-attinguie-logo.png');
        }
        try { $path = $this->logos->path($filename); }
        catch (\InvalidArgumentException) { throw $this->createNotFoundException('Logo introuvable.'); }
        if (!is_file($path) || !is_readable($path)) {
            throw $this->createNotFoundException('Logo indisponible.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) throw $this->createNotFoundException('Logo indisponible.');
        return new Response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{appName:string,organizationName:string,dashboardDescription:string,logoUrl:string,version:int} */
    public static function payload(AppBranding $brand): array
    {
        return [
            'appName' => $brand->getAppName(),
            'organizationName' => $brand->getOrganizationName(),
            'dashboardDescription' => $brand->getDashboardDescription(),
            'logoUrl' => $brand->getLogoFilename() === null
                ? '/gc-attinguie-logo.png'
                : '/api/public/branding/logo?v=' . $brand->getVersion(),
            'version' => $brand->getVersion(),
        ];
    }
}
