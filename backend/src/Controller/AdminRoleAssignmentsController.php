<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/rbac/users')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRoleAssignmentsController extends AbstractController
{
    private const CSRF_ID = 'gc_admin_users_v1';
    private const MAX_ROLES = 10;
    private const MAX_CSV_ROWS = 100;

    public function __construct(private readonly Connection $db, private readonly CsrfTokenManagerInterface $csrf) {}

    #[Route('', name: 'api_admin_rbac_users_list', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, min(10000, $request->query->getInt('page', 1)));
        $search = trim((string) $request->query->get('search', ''));
        if (mb_strlen($search) > 100) return $this->json(['error' => 'Recherche trop longue.'], 422);
        $params = $search === '' ? [] : ['search' => '%' . $search . '%'];
        $where = $search === '' ? '' : 'WHERE u.email ILIKE :search OR p.first_name ILIKE :search OR p.last_name ILIKE :search';
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id ' . $where, $params);
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.email, u.roles, u.is_active, p.first_name, p.last_name
               FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id ' . $where .
            ' ORDER BY u.id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), $params
        );
        return $this->json([
            'items' => array_map(fn (array $r): array => [
                'id' => (int) $r['id'], 'email' => $r['email'], 'roles' => $this->decode($r['roles']),
                'isActive' => $this->dbBool($r['is_active']),
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            ], $rows),
            'total' => $total, 'page' => $page, 'pageSize' => 20,
            'roles' => $this->db->fetchAllAssociative('SELECT code, label FROM security_roles ORDER BY is_system DESC, label'),
            'csrf' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]);
    }

    #[Route('/{id<\d+>}/roles', name: 'api_admin_rbac_users_assign', methods: ['PUT'])]
    public function assign(int $id, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            return $this->json(['error' => 'JSON requis.'], 415);
        }
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data) || array_keys($data) !== ['roles']) {
                throw new \InvalidArgumentException('Objet contenant uniquement roles requis.');
            }
            $roles = $this->validateRoles($data['roles']);
        } catch (\JsonException|\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        $actor = $this->actorId();
        try {
        return $this->db->transactional(function (Connection $db) use ($id, $actor, $roles): JsonResponse {
            $this->securityLock($db);
            if ($id === $actor) return $this->json(['error' => 'Modification de vos propres rôles interdite.'], 409);
            $existing = $db->fetchAssociative('SELECT id, roles, is_active FROM users WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($existing === false) return $this->json(['error' => 'Compte introuvable.'], 404);
            $this->assertCatalogRoles($db, $roles);
            $old = $this->decode($existing['roles']);
            if ($this->sameRoles($old, $roles)) return $this->json(['ok' => true, 'unchanged' => true]);
            if (in_array('ROLE_ADMIN', $old, true) && !in_array('ROLE_ADMIN', $roles, true)
                && $this->dbBool($existing['is_active']) && $this->activeAdminCount($db) <= 1) {
                return $this->json(['error' => 'Dernier administrateur actif : modification refusée.'], 409);
            }
            $db->update('users', ['roles' => json_encode($roles, JSON_THROW_ON_ERROR)], ['id' => $id]);
            $this->audit($db, $actor, $id, 'rbac_user_roles_changed', ['before' => $old, 'after' => $roles]);
            return $this->json(['ok' => true, 'roles' => $roles, 'sessionMustRefresh' => true]);
        });
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Import CSV email;roles. Transaction atomique, refuse tout compte administrateur et toute promotion admin. */
    #[Route('/import', name: 'api_admin_rbac_users_assign_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) return $this->json(['error' => 'Jeton CSRF invalide.'], 403);
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() === false || $file->getSize() > 1048576) {
            return $this->json(['error' => 'CSV obligatoire, maximum 1 Mo.'], 422);
        }
        try {
            $rows = $this->parseCsv($file);
            $seen = [];
            foreach ($rows as &$row) {
                $email = mb_strtolower(trim($row['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
                    throw new \InvalidArgumentException('Adresse email invalide ligne ' . $row['line']);
                }
                if (isset($seen[$email])) throw new \InvalidArgumentException('Email en double dans le CSV : ligne ' . $row['line']);
                $seen[$email] = true;
                $roleCodes = array_map('trim', explode('|', $row['roles']));
                $row = ['email' => $email, 'roles' => $this->validateRoles($roleCodes), 'line' => $row['line']];
                if (in_array('ROLE_ADMIN', $row['roles'], true)) {
                    throw new \InvalidArgumentException('ROLE_ADMIN interdit par import ; utilisez une attribution individuelle.');
                }
            }
            unset($row);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        $actor = $this->actorId();
        $preview = $request->query->get('dry_run') === '1';
        try {
            $result = $this->db->transactional(function (Connection $db) use ($rows, $actor, $preview): array {
                $this->securityLock($db);
                $allowed = array_fill_keys($db->fetchFirstColumn('SELECT code FROM security_roles'), true);
                $plans = [];
                $skipped = 0;
                foreach ($rows as $row) {
                    foreach ($row['roles'] as $code) {
                        if (!isset($allowed[$code])) throw new \InvalidArgumentException('Rôle inconnu ' . $code . ' ligne ' . $row['line']);
                    }
                    $user = $db->fetchAssociative('SELECT id, roles FROM users WHERE LOWER(email) = :email FOR UPDATE', ['email' => $row['email']]);
                    if ($user === false) throw new \InvalidArgumentException('Compte introuvable ligne ' . $row['line'] . ' : ' . $row['email']);
                    if ((int) $user['id'] === $actor) throw new \InvalidArgumentException('Votre propre compte est dans le CSV.');
                    $old = $this->decode($user['roles']);
                    if (in_array('ROLE_ADMIN', $old, true)) throw new \InvalidArgumentException('Modification d’un administrateur interdite en masse.');
                    if ($this->sameRoles($old, $row['roles'])) { ++$skipped; continue; }
                    $plans[] = ['id' => (int) $user['id'], 'old' => $old, 'new' => $row['roles']];
                }
                if (!$preview) {
                    foreach ($plans as $change) {
                        $db->update('users', ['roles' => json_encode($change['new'], JSON_THROW_ON_ERROR)], ['id' => $change['id']]);
                        $this->audit($db, $actor, $change['id'], 'rbac_user_roles_changed', [
                            'before' => $change['old'], 'after' => $change['new'], 'source' => 'csv',
                        ]);
                    }
                }
                return ['preview' => $preview, 'toUpdate' => count($plans), 'skipped' => $skipped];
            });
            return $this->json($result);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'noChanges' => true], 422);
        }
    }

    /** @return list<array{email:string,roles:string,line:int}> */
    private function parseCsv(UploadedFile $file): array
    {
        $raw = file_get_contents($file->getPathname());
        if ($raw === false || !mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            throw new \InvalidArgumentException('CSV UTF-8 invalide.');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $line = strtok($raw, "\r\n");
        if ($line === false) throw new \InvalidArgumentException('CSV vide.');
        $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'w+');
        if ($fh === false) throw new \InvalidArgumentException('Impossible de lire le CSV.');
        try {
            fwrite($fh, $raw);
            rewind($fh);
            $header = fgetcsv($fh, 0, $delimiter, '"', '');
            if (!is_array($header)) throw new \InvalidArgumentException('En-tête CSV manquant.');
            $header = array_map(static fn ($s) => mb_strtolower(trim((string) $s)), $header);
            if ($header !== ['email', 'roles']) throw new \InvalidArgumentException('En-tête exact attendu : email;roles.');
            $list = [];
            $number = 1;
            while (($values = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
                ++$number;
                if ($values === [null]) continue;
                if (count($values) !== 2) throw new \InvalidArgumentException('Nombre de colonnes incorrect ligne ' . $number);
                if (count($list) >= self::MAX_CSV_ROWS) throw new \InvalidArgumentException('100 utilisateurs maximum.');
                $list[] = ['email' => trim($values[0]), 'roles' => trim($values[1]), 'line' => $number];
            }
            if ($list === []) throw new \InvalidArgumentException('CSV sans données.');
            return $list;
        } finally { fclose($fh); }
    }

    /** @return list<string> */
    private function validateRoles(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) < 1 || count($input) > self::MAX_ROLES) {
            throw new \InvalidArgumentException('Entre 1 et 10 rôles requis.');
        }
        $seen = [];
        foreach ($input as $code) {
            if (!is_string($code) || !preg_match('/^ROLE_[A-Z0-9_]{2,59}$/D', $code)) {
                throw new \InvalidArgumentException('Code de rôle invalide.');
            }
            if (isset($seen[$code])) throw new \InvalidArgumentException('Rôle en double.');
            $seen[$code] = true;
        }
        return array_keys($seen);
    }

    /** @param list<string> $roles */
    private function assertCatalogRoles(Connection $db, array $roles): void
    {
        foreach ($roles as $code) {
            if (!$db->fetchOne('SELECT 1 FROM security_roles WHERE code = :code', ['code' => $code])) {
                throw new \InvalidArgumentException('Rôle inconnu dans le catalogue : ' . $code);
            }
        }
    }

    private function validCsrf(Request $r): bool
    {
        $value = $r->headers->get('X-CSRF-Token');
        return is_string($value) && $this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $value));
    }

    private function actorId(): int
    {
        $u = $this->getUser();
        if (!$u instanceof User || $u->getId() === null) throw $this->createAccessDeniedException();
        return $u->getId();
    }

    private function securityLock(Connection $db): void
    {
        // Même verrou que Users V2 : protection concurrente du dernier administrateur.
        $db->fetchOne('SELECT pg_advisory_xact_lock(20261029, 2)');
    }

    private function activeAdminCount(Connection $db): int
    {
        return (int) $db->fetchOne("SELECT COUNT(*) FROM users WHERE is_active = TRUE AND roles::jsonb @> '[\"ROLE_ADMIN\"]'::jsonb");
    }

    /** @return list<string> */
    private function decode(mixed $raw): array
    {
        $data = json_decode((string) $raw, true);
        return is_array($data) && array_is_list($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    private function sameRoles(array $a, array $b): bool
    {
        sort($a);
        sort($b);
        return $a === $b;
    }

    private function dbBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /** @param array<string,mixed> $details */
    private function audit(Connection $db, int $actor, int $target, string $event, array $details): void
    {
        $db->insert('admin_user_audit', [
            'actor_user_id' => $actor, 'target_user_id' => $target,
            'action' => $event, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
