<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppBranding;
use App\Entity\AppBrandingAudit;
use App\Entity\User;
use App\Service\AppLogoStorage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** La permission est imposée par Symfony, pas par le seul menu Vue. */
#[Route('/api/admin/branding')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBrandingController extends AbstractController
{
    private const CSRF_ID = 'gc_app_branding_v1';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AppLogoStorage $logos,
    ) {}

    #[Route('', name: 'gc_admin_branding_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $brand = $this->em->find(AppBranding::class, 1) ?? new AppBranding();
        $response = $this->json([
            ...PublicBrandingController::payload($brand),
            'csrf' => (string) $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('', name: 'gc_admin_branding_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        if ($request->getContentTypeFormat() !== 'json') return $this->json(['error' => 'JSON requis.'], 415);
        try { $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return $this->json(['error' => 'JSON invalide.'], 422); }
        if (!is_array($data) || array_is_list($data) ||
            array_diff(array_keys($data), ['appName', 'organizationName', 'dashboardDescription', 'version']) !== [] ||
            array_diff(['appName', 'organizationName', 'dashboardDescription', 'version'], array_keys($data)) !== []) {
            return $this->json(['error' => 'Champs autorisés : appName, organizationName, dashboardDescription, version.'], 422);
        }
        if (!is_int($data['version']) || $data['version'] < 1) {
            return $this->json(['error' => 'Version invalide. Rechargez la page.'], 422);
        }
        try {
            $name = $this->validText($data['appName'], 3, 100, false);
            $org = $this->validText($data['organizationName'], 3, 150, false);
            $description = $this->validText($data['dashboardDescription'], 10, 500, true);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        try {
            $result = $this->em->wrapInTransaction(function () use ($data, $name, $org, $description): array {
                $brand = $this->lockedBrand();
                if ($brand->getVersion() !== $data['version']) throw new \DomainException('Cette identité a été modifiée par ailleurs. Actualisez la page.');
                $before = $brand->snapshot();
                $brand->setContent($name, $org, $description);
                if ($before['appName'] !== $name || $before['organizationName'] !== $org || $before['dashboardDescription'] !== $description) {
                    $actor = $this->actorId();
                    $brand->touch($actor);
                    $this->em->persist(new AppBrandingAudit($actor, 'branding_text_updated', $before, $brand->snapshot()));
                }
                return PublicBrandingController::payload($brand);
            });
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
        $response = $this->json($result);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/logo', name: 'gc_admin_branding_logo_upload', methods: ['POST'])]
    public function uploadLogo(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        $rawVersion = $request->request->get('version');
        if (!is_string($rawVersion) || !ctype_digit($rawVersion) || (int) $rawVersion < 1) {
            return $this->json(['error' => 'Version invalide. Rechargez la page.'], 422);
        }
        $file = $request->files->get('logo');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'Logo manquant (PNG, JPEG ou WebP).'], 422);
        }
        // Vérification avant décodage pour éviter un traitement inutile sur un formulaire périmé.
        $current = $this->em->find(AppBranding::class, 1);
        if (!$current || $current->getVersion() !== (int) $rawVersion) {
            return $this->json(['error' => 'Cette identité a été modifiée par ailleurs. Actualisez la page.'], 409);
        }
        try {
            $filename = $this->logos->save($file);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException) {
            return $this->json(['error' => 'Stockage du logo indisponible.'], 503);
        }
        try {
            $result = $this->em->wrapInTransaction(function () use ($filename, $rawVersion): array {
                $brand = $this->lockedBrand();
                if ($brand->getVersion() !== (int) $rawVersion) throw new \DomainException('Modification concurrente : rechargez la page.');
                $before = $brand->snapshot();
                $brand->setLogoFilename($filename);
                $actor = $this->actorId();
                $brand->touch($actor);
                $this->em->persist(new AppBrandingAudit($actor, 'branding_logo_uploaded', $before, $brand->snapshot()));
                return PublicBrandingController::payload($brand);
            });
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
        $response = $this->json($result);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    private function lockedBrand(): AppBranding
    {
        $brand = $this->em->find(AppBranding::class, 1, LockMode::PESSIMISTIC_WRITE);
        if (!$brand) throw new \RuntimeException('Paramètres absents : exécutez la migration Doctrine.');
        return $brand;
    }

    private function actorId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) throw $this->createAccessDeniedException();
        return $user->getId();
    }

    private function validCsrf(Request $request): bool
    {
        $value = $request->headers->get('X-CSRF-Token');
        return is_string($value) && $this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $value));
    }

    private function validText(mixed $value, int $min, int $max, bool $multiline): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException('Une valeur texte est requise.');
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($value));
        $invalid = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        if (mb_strlen($normalized) < $min || mb_strlen($normalized) > $max || preg_match($invalid, $normalized)) {
            throw new \InvalidArgumentException(sprintf('Texte invalide : entre %d et %d caractères.', $min, $max));
        }
        return $normalized;
    }
}
