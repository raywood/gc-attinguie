<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ProfilePhotoStorage;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/account')]
#[IsGranted('ROLE_USER')]
final class SelfProfileController extends AbstractController
{
    private const CSRF_ID = 'gc_self_profile';

    public function __construct(
        private readonly Connection $conn,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ProfilePhotoStorage $photos,
    ) {}

    #[Route('/profile', name: 'api_account_profile', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $userId = $this->currentUserId();
        $row = $this->conn->fetchAssociative(
            'SELECT u.id, u.email, u.roles, p.first_name, p.last_name, p.phone, p.job_title
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = :id',
            ['id' => $userId],
        );
        if ($row === false) return $this->json(['error' => 'Compte introuvable.'], 404);
        return $this->json([
            'id' => $userId,
            'email' => $row['email'],
            'roles' => json_decode((string) $row['roles'], true) ?: [],
            'firstName' => $row['first_name'] ?? '',
            'lastName' => $row['last_name'] ?? '',
            'phone' => $row['phone'] ?? '',
            'jobTitle' => $row['job_title'] ?? '',
            'hasPhoto' => $this->photos->exists($userId),
            'csrf' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]);
    }

    #[Route('/profile', name: 'api_account_profile_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            return $this->json(['error' => 'Corps JSON requis.'], 415);
        }
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], 422);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->json(['error' => 'Objet JSON requis.'], 422);
        }
        $keys = ['firstName', 'lastName', 'phone', 'jobTitle'];
        if (array_diff(array_keys($payload), $keys) !== [] || array_diff($keys, array_keys($payload)) !== []) {
            return $this->json(['error' => 'Seuls le prénom, le nom, le téléphone et la fonction peuvent être modifiés.'], 422);
        }
        $limits = ['firstName' => 80, 'lastName' => 80, 'phone' => 32, 'jobTitle' => 120];
        foreach ($limits as $key => $limit) {
            if (!is_string($payload[$key])) return $this->json(['error' => $key . ' doit être un texte.'], 422);
            $payload[$key] = trim($payload[$key]);
            if (mb_strlen($payload[$key]) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $payload[$key])) {
                return $this->json(['error' => 'Valeur invalide pour ' . $key], 422);
            }
        }
        if ($payload['firstName'] === '' || $payload['lastName'] === '') {
            return $this->json(['error' => 'Le nom et le prénom sont obligatoires.'], 422);
        }
        $id = $this->currentUserId();
        $new = [
            'first_name' => $payload['firstName'],
            'last_name' => $payload['lastName'],
            'phone' => $payload['phone'] !== '' ? $payload['phone'] : null,
            'job_title' => $payload['jobTitle'] !== '' ? $payload['jobTitle'] : null,
        ];
        $this->conn->transactional(function (Connection $c) use ($id, $new): void {
            $previous = $c->fetchAssociative('SELECT first_name, last_name, phone, job_title FROM user_profiles WHERE user_id = :id FOR UPDATE', ['id' => $id]);
            if ($previous === false) {
                // Les comptes historiques n'avaient pas nécessairement de fiche complémentaire.
                $c->insert('user_profiles', [
                    'user_id' => $id,
                    ...$new,
                    'created_at' => gmdate('Y-m-d H:i:sP'),
                ]);
                $changed = array_keys($new);
            } else {
                $changed = [];
                foreach ($new as $key => $value) {
                    if ($previous[$key] !== $value) $changed[] = $key;
                }
                if ($changed) $c->update('user_profiles', $new, ['user_id' => $id]);
            }
            if ($changed) $this->audit($c, $id, 'profile_self_updated', ['changedFields' => $changed]);
        });
        return $this->json(['ok' => true]);
    }

    #[Route('/photo', name: 'api_account_photo_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) return $this->json(['error' => 'Photo manquante.'], 422);
        $id = $this->currentUserId();
        try {
            $this->photos->save($id, $file);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 503);
        }
        $this->audit($this->conn, $id, 'profile_photo_uploaded', []);
        return $this->json(['ok' => true, 'photoUrl' => '/api/account/photo/' . $id]);
    }

    #[Route('/photo', name: 'api_account_photo_delete', methods: ['DELETE'])]
    public function deletePhoto(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        $id = $this->currentUserId();
        try {
            $this->photos->remove($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 503);
        }
        $this->audit($this->conn, $id, 'profile_photo_removed', []);
        return $this->json(['ok' => true]);
    }

    #[Route('/photo/{id<\d+>}', name: 'api_account_photo_view', methods: ['GET'])]
    public function viewPhoto(int $id): Response
    {
        if ($id < 1 || ($id !== $this->currentUserId() && !$this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('Accès à la photo non autorisé.');
        }
        $path = $this->photos->path($id);
        if (!is_file($path) || !is_readable($path)) throw $this->createNotFoundException('Aucune photo disponible.');
        $content = file_get_contents($path);
        if ($content === false) throw $this->createNotFoundException('Photo indisponible.');
        return new Response($content, 200, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function currentUserId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) throw $this->createAccessDeniedException();
        return $user->getId();
    }

    private function validCsrf(Request $request): bool
    {
        $raw = $request->headers->get('X-CSRF-Token');
        return is_string($raw) && $this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $raw));
    }

    /** Le journal ne stocke jamais de photo, d'email ou de mot de passe. */
    private function audit(Connection $conn, int $id, string $action, array $details): void
    {
        $conn->insert('admin_user_audit', [
            'actor_user_id' => $id,
            'target_user_id' => $id,
            'action' => $action,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
