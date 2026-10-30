<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUsersV2Controller extends AbstractController
{
    private const ROLES = ['ROLE_AGENT', 'ROLE_REGISSEUR', 'ROLE_COMPTABLE', 'ROLE_ADMIN'];
    private const CSRF_ID = 'gc_admin_users_v1'; // Réutilise le token déjà fourni par la liste V1.

    public function __construct(
        private readonly Connection $conn,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('/{id<\d+>}', name: 'api_admin_users_v2_edit', methods: ['PATCH'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide. Rechargez la page.'], 403);
        $data = $this->jsonBody($request);
        if ($data instanceof JsonResponse) return $data;
        $expected = ['first_name','last_name','phone','job_title','role'];
        if (array_diff(array_keys($data), $expected) || array_diff($expected, array_keys($data))) {
            return $this->json(['error' => 'Champs attendus : first_name, last_name, phone, job_title, role.'], 422);
        }
        foreach ($expected as $key) {
            if (!is_string($data[$key])) return $this->json(['error' => 'Valeur invalide : ' . $key], 422);
            $data[$key] = trim($data[$key]);
        }
        $data['role'] = strtoupper($data['role']);
        if (!$this->validText($data['first_name'], 80, true) || !$this->validText($data['last_name'], 80, true)
            || !$this->validText($data['phone'], 32) || !$this->validText($data['job_title'], 120)
            || !in_array($data['role'], self::ROLES, true)) {
            return $this->json(['error' => 'Nom, prénom, téléphone, fonction ou rôle invalide.'], 422);
        }
        $actorId = $this->actorId();
        return $this->conn->transactional(function (Connection $c) use ($id, $actorId, $data): JsonResponse {
            $this->serializeSecurityMutations($c);
            $row = $this->loadLocked($c, $id);
            if ($row === false) return $this->json(['error' => 'Utilisateur introuvable.'], 404);
            $oldRoles = $this->decodeRoles($row['roles']);
            $oldRole = $this->mainRole($oldRoles);
            $roleChanged = $oldRole !== $data['role'];
            if ($roleChanged && $id === $actorId) {
                return $this->json(['error' => 'Vous ne pouvez pas modifier votre propre rôle.'], 409);
            }
            if ($roleChanged && $oldRole === 'ROLE_ADMIN' && $data['role'] !== 'ROLE_ADMIN' && $this->dbBool($row['is_active'])
                && $this->activeAdminCount($c) <= 1) {
                return $this->json(['error' => 'Impossible de retirer le rôle du dernier administrateur actif.'], 409);
            }
            $profileData = [
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                'phone' => $data['phone'] !== '' ? $data['phone'] : null,
                'job_title' => $data['job_title'] !== '' ? $data['job_title'] : null,
            ];
            $changed = [];
            foreach ($profileData as $key => $value) {
                if (($row[$key] ?? null) !== $value) $changed[] = $key;
            }
            if ($row['profile_id'] !== null) {
                $c->update('user_profiles', $profileData, ['id' => $row['profile_id']]);
            } else {
                // Le compte historique sans profil reçoit une fiche complémentaire.
                $c->insert('user_profiles', [
                    'user_id' => $id, ...$profileData, 'invitation_token_hash' => null,
                    'invitation_expires_at' => null, 'activated_at' => null,
                    'created_at' => gmdate('Y-m-d H:i:sP'),
                ]);
            }
            if ($roleChanged) {
                $c->update('users', ['roles' => json_encode([$data['role']], JSON_THROW_ON_ERROR)], ['id' => $id]);
            }
            if ($changed !== [] || $roleChanged) {
                $this->log($c, $actorId, $id, 'user_updated', [
                    'changedFields' => $changed,
                    'roleBefore' => $roleChanged ? $oldRole : null,
                    'roleAfter' => $roleChanged ? $data['role'] : null,
                ]);
            }
            return $this->json(['ok' => true, 'changedFields' => $changed, 'roleChanged' => $roleChanged]);
        });
    }

    #[Route('/{id<\d+>}/status', name: 'api_admin_users_v2_status', methods: ['POST'])]
    public function status(int $id, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        $data = $this->jsonBody($request);
        if ($data instanceof JsonResponse) return $data;
        if (!array_key_exists('active', $data) || !is_bool($data['active']) || count($data) !== 1) {
            return $this->json(['error' => 'Champ booléen active requis.'], 422);
        }
        $active = $data['active'];
        $actorId = $this->actorId();
        return $this->conn->transactional(function (Connection $c) use ($id, $actorId, $active): JsonResponse {
            $this->serializeSecurityMutations($c);
            $row = $this->loadLocked($c, $id);
            if ($row === false) return $this->json(['error' => 'Utilisateur introuvable.'], 404);
            $current = $this->dbBool($row['is_active']);
            if ($current === $active) return $this->json(['error' => 'Le compte est déjà dans cet état.'], 409);
            if (!$active && $id === $actorId) return $this->json(['error' => 'Impossible de suspendre votre propre compte.'], 409);
            if (!$active && $this->mainRole($this->decodeRoles($row['roles'])) === 'ROLE_ADMIN' && $this->activeAdminCount($c) <= 1) {
                return $this->json(['error' => 'Impossible de suspendre le dernier administrateur actif.'], 409);
            }
            // Un compte jamais activé ou en attente de réinitialisation ne peut pas être activé sans mot de passe choisi.
            if ($active && $row['profile_id'] !== null && $row['invitation_token_hash'] !== null) {
                return $this->json(['error' => 'Une invitation attend une activation : le titulaire doit choisir son mot de passe.'], 409);
            }
            $c->update('users', ['is_active' => $active], ['id' => $id], ['is_active' => ParameterType::BOOLEAN]);
            $this->log($c, $actorId, $id, $active ? 'user_reactivated' : 'user_suspended', [
                'activeBefore' => $current, 'activeAfter' => $active,
            ]);
            return $this->json(['ok' => true, 'isActive' => $active]);
        });
    }

    /** Réinitialisation d'un mot de passe actif = désactivation + invitation à usage unique. */
    #[Route('/{id<\d+>}/reset-password', name: 'api_admin_users_v2_reset_password', methods: ['POST'])]
    public function resetPassword(int $id, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        $actorId = $this->actorId();
        return $this->conn->transactional(function (Connection $c) use ($id, $actorId): JsonResponse {
            $this->serializeSecurityMutations($c);
            $row = $this->loadLocked($c, $id);
            if ($row === false) return $this->json(['error' => 'Utilisateur introuvable.'], 404);
            if ($actorId === $id) return $this->json(['error' => 'Vous ne pouvez pas réinitialiser votre propre compte depuis cette page.'], 409);
            $isActive = $this->dbBool($row['is_active']);
            $resetPending = !$isActive && $row['invitation_token_hash'] !== null;
            if (!$isActive && !$resetPending) return $this->json(['error' => 'Le compte est suspendu ou non activé : réactivez-le d’abord, ou renouvelez une invitation V1.'], 409);
            if ($row['profile_id'] === null) return $this->json(['error' => 'Complétez d’abord le profil de ce compte historique.'], 409);
            if ($isActive && $this->mainRole($this->decodeRoles($row['roles'])) === 'ROLE_ADMIN' && $this->activeAdminCount($c) <= 1) {
                return $this->json(['error' => 'Impossible de désactiver le dernier administrateur actif pour réinitialisation.'], 409);
            }
            $token = bin2hex(random_bytes(32));
            $expires = new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC'));
            $c->update('users', ['is_active' => false], ['id' => $id], ['is_active' => ParameterType::BOOLEAN]);
            $c->update('user_profiles', [
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => $expires->format('Y-m-d H:i:sP'),
            ], ['user_id' => $id]);
            $this->log($c, $actorId, $id, 'password_reset_requested', ['accountSuspended' => true]);
            // Le token n'est ni journalisé ni conservé en clair. Affichage unique à l'administrateur.
            return $this->json(['email' => $row['email'], 'token' => $token, 'expiresAt' => $expires->format(DATE_ATOM)]);
        });
    }

    #[Route('/audit', name: 'api_admin_users_v2_audit', methods: ['GET'], priority: 10)]
    public function audit(Request $request): JsonResponse
    {
        $page = max(1, min(10000, $request->query->getInt('page', 1)));
        $target = $request->query->get('targetId');
        if ($target !== null && (!is_string($target) || !preg_match('/^[1-9]\d*$/D', $target))) {
            return $this->json(['error' => 'Identifiant cible invalide.'], 422);
        }
        $where = $target !== null ? 'WHERE a.target_user_id = :target' : '';
        $params = $target !== null ? ['target' => (int) $target] : [];
        $total = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM admin_user_audit a ' . $where, $params);
        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.id, a.action, a.details, a.created_at, a.actor_user_id, a.target_user_id,
                    actor.email AS actor_email, target.email AS target_email
               FROM admin_user_audit a
               LEFT JOIN users actor ON actor.id = a.actor_user_id
               LEFT JOIN users target ON target.id = a.target_user_id '
            . $where . ' ORDER BY a.id DESC LIMIT 30 OFFSET ' . (($page - 1) * 30), $params,
        );
        return $this->json(['items' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'action' => $r['action'],
            'details' => json_decode((string) $r['details'], true) ?: [],
            'createdAt' => $r['created_at'], 'actorId' => $r['actor_user_id'],
            'actorEmail' => $r['actor_email'], 'targetId' => $r['target_user_id'],
            'targetEmail' => $r['target_email'],
        ], $rows), 'page' => $page, 'pageSize' => 30, 'total' => $total]);
    }

    private function validCsrf(Request $request): bool
    {
        $raw = $request->headers->get('X-CSRF-Token');
        return is_string($raw) && $this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $raw));
    }

    /** @return array<string,mixed>|JsonResponse */
    private function jsonBody(Request $request): array|JsonResponse
    {
        if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            return $this->json(['error' => 'JSON requis.'], 415);
        }
        try {
            $value = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], 422);
        }
        if (!is_array($value) || array_is_list($value)) return $this->json(['error' => 'Objet JSON requis.'], 422);
        return $value;
    }

    private function actorId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) throw $this->createAccessDeniedException();
        return $user->getId();
    }

    private function serializeSecurityMutations(Connection $c): void
    {
        // Verrou transactionnel PostgreSQL commun à tous les changements V2 de rôle ou d'état.
        $c->fetchOne('SELECT pg_advisory_xact_lock(20261029, 2)');
    }

    /** @return array<string,mixed>|false */
    private function loadLocked(Connection $c, int $id): array|false
    {
        return $c->fetchAssociative(
            'SELECT u.id, u.email, u.roles, u.is_active, p.id AS profile_id,
                    p.first_name, p.last_name, p.phone, p.job_title,
                    p.invitation_token_hash, p.activated_at
               FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.id = :id FOR UPDATE OF u', ['id' => $id],
        );
    }

    /** @return list<string> */
    private function decodeRoles(mixed $value): array
    {
        $roles = json_decode((string) $value, true);
        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    /** @param list<string> $roles */
    private function mainRole(array $roles): string
    {
        foreach (['ROLE_ADMIN','ROLE_REGISSEUR','ROLE_COMPTABLE','ROLE_AGENT'] as $role) {
            if (in_array($role, $roles, true)) return $role;
        }
        return 'ROLE_AGENT';
    }

    private function activeAdminCount(Connection $c): int
    {
        return (int) $c->fetchOne("SELECT COUNT(*) FROM users WHERE is_active = TRUE AND roles::jsonb @> '[\"ROLE_ADMIN\"]'::jsonb");
    }

    private function dbBool(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 't', 'true'], true);
    }

    private function validText(string $value, int $max, bool $required = false): bool
    {
        return (!$required || $value !== '') && mb_strlen($value) <= $max && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value);
    }

    /** @param array<string,mixed> $details */
    private function log(Connection $c, int $actorId, int $targetId, string $action, array $details): void
    {
        $c->insert('admin_user_audit', [
            'actor_user_id' => $actorId, 'target_user_id' => $targetId,
            'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
