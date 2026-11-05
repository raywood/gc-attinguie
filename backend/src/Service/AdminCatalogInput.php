<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminCatalogInput
{
    private const CSRF_ID = 'gc_admin_catalog_v1';

    public function __construct(private readonly CsrfTokenManagerInterface $csrf) {}

    public function token(): string { return (string) $this->csrf->getToken(self::CSRF_ID)->getValue(); }

    /** @return array<string,mixed>|JsonResponse */
    public function body(Request $request): array|JsonResponse
    {
        $value = $request->headers->get('X-CSRF-Token');
        if (!is_string($value) || !$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $value))) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide. Rechargez la page.'], 403);
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return new JsonResponse(['error' => 'Content-Type application/json requis.'], 415);
        }
        try { $data = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return new JsonResponse(['error' => 'Objet JSON invalide.'], 422); }
        if (!$data instanceof \stdClass) return new JsonResponse(['error' => 'Objet JSON requis.'], 422);
        return (array) $data;
    }
}
