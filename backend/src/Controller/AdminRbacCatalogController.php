<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * V3 : gestion du CATALOGUE (rôles, permissions et associations).
 * Ne change ni users.roles, ni security.yaml, ni les autorisations métier existantes.
 */
#[Route('/api/admin/rbac')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRbacCatalogController extends AbstractController
{
    private const CSRF_ID = 'gc_admin_users_v1';
    private const MAX_ROWS = 500;
    private const MAX_FILE_BYTES = 2097152;

    public function __construct(
        private readonly Connection $db,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('', name: 'api_admin_rbac_catalog', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $roles = $this->db->fetchAllAssociative(
            'SELECT id, code, label, description, is_system, created_at FROM security_roles ORDER BY is_system DESC, label, code'
        );
        $perms = $this->db->fetchAllAssociative(
            'SELECT id, code, label, module, description, created_at FROM security_permissions ORDER BY module, code'
        );
        $links = $this->db->fetchAllAssociative(
            'SELECT rp.role_id, p.code FROM security_role_permissions rp
             JOIN security_permissions p ON p.id = rp.permission_id ORDER BY p.code'
        );
        $byRole = [];
        foreach ($links as $link) $byRole[(int) $link['role_id']][] = $link['code'];
        return $this->json([
            'roles' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['label'],
                'description' => $r['description'] ?? '',
                'isSystem' => in_array($r['is_system'], [true, 1, '1', 't', 'true'], true),
                'permissions' => $byRole[(int) $r['id']] ?? [],
            ], $roles),
            'permissions' => array_map(static fn (array $p): array => [
                'id' => (int) $p['id'], 'code' => $p['code'], 'label' => $p['label'],
                'module' => $p['module'], 'description' => $p['description'] ?? '',
            ], $perms),
            'csrf' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
            'catalogOnly' => true,
        ]);
    }

    #[Route('/permissions', name: 'api_admin_rbac_permission_create', methods: ['POST'])]
    public function createPermission(Request $request): JsonResponse
    {
        if ($error = $this->checkCsrf($request)) return $error;
        try { $row = $this->validatePermission($this->body($request)); }
        catch (\InvalidArgumentException $e) { return $this->json(['error' => $e->getMessage()], 422); }
        try {
            return $this->db->transactional(function (Connection $db) use ($row): JsonResponse {
                $this->insertPermission($db, $row);
                $this->audit($db, 'rbac_perm_created', ['code' => $row['code']]);
                return $this->json(['created' => $row], 201);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Ce code de permission existe déjà.'], 409);
        }
    }

    #[Route('/roles', name: 'api_admin_rbac_role_create', methods: ['POST'])]
    public function createRole(Request $request): JsonResponse
    {
        if ($error = $this->checkCsrf($request)) return $error;
        try { $row = $this->validateRole($this->body($request)); }
        catch (\InvalidArgumentException $e) { return $this->json(['error' => $e->getMessage()], 422); }
        try {
            return $this->db->transactional(function (Connection $db) use ($row): JsonResponse {
                $permissionIds = $this->permissionIds($db, $row['permissions']);
                $id = $this->insertRole($db, $row);
                $this->linkPermissions($db, $id, $permissionIds);
                $this->audit($db, 'rbac_role_created', ['code' => $row['code'], 'permissions' => $row['permissions']]);
                return $this->json(['created' => ['id' => $id, ...$row]], 201);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Ce code de rôle existe déjà.'], 409);
        }
    }

    #[Route('/roles/{id<\d+>}/permissions', name: 'api_admin_rbac_role_permissions', methods: ['PUT'])]
    public function replaceRolePermissions(int $id, Request $request): JsonResponse
    {
        if ($error = $this->checkCsrf($request)) return $error;
        try {
            $data = $this->body($request);
            if (array_keys($data) !== ['permissions']) throw new \InvalidArgumentException('Le champ permissions est requis.');
            $codes = $this->validatePermissionCodes($data['permissions']);
            return $this->db->transactional(function (Connection $db) use ($id, $codes): JsonResponse {
                $r = $db->fetchAssociative('SELECT code FROM security_roles WHERE id = :id FOR UPDATE', ['id' => $id]);
                if ($r === false) return $this->json(['error' => 'Rôle introuvable.'], 404);
                $ids = $this->permissionIds($db, $codes);
                $db->executeStatement('DELETE FROM security_role_permissions WHERE role_id = :id', ['id' => $id]);
                $this->linkPermissions($db, $id, $ids);
                $this->audit($db, 'rbac_role_perms_set', ['role' => $r['code'], 'permissions' => $codes]);
                return $this->json(['ok' => true, 'role' => $r['code'], 'permissions' => $codes]);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/permissions/import', name: 'api_admin_rbac_permission_import', methods: ['POST'])]
    public function importPermissions(Request $request): JsonResponse
    {
        return $this->importCatalog($request, 'permission');
    }

    #[Route('/roles/import', name: 'api_admin_rbac_role_import', methods: ['POST'])]
    public function importRoles(Request $request): JsonResponse
    {
        return $this->importCatalog($request, 'role');
    }

    private function importCatalog(Request $request, string $kind): JsonResponse
    {
        if ($error = $this->checkCsrf($request)) return $error;
        $dryRun = $request->query->get('dry_run') === '1';
        try {
            $rows = $this->readCsv($request, $kind);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        $table = $kind === 'role' ? 'security_roles' : 'security_permissions';
        $exists = array_fill_keys($this->db->fetchFirstColumn('SELECT code FROM ' . $table), true);
        $allPermissions = array_fill_keys($this->db->fetchFirstColumn('SELECT code FROM security_permissions'), true);
        $seen = []; $create = []; $errors = []; $skipped = 0;
        foreach ($rows as $item) {
            try {
                $row = $kind === 'role' ? $this->validateRole($item['data']) : $this->validatePermission($item['data']);
                if (isset($seen[$row['code']])) throw new \InvalidArgumentException('Code répété dans le fichier.');
                $seen[$row['code']] = true;
                if (isset($exists[$row['code']])) { ++$skipped; continue; }
                if ($kind === 'role') {
                    foreach ($row['permissions'] as $code) {
                        if (!isset($allPermissions[$code])) throw new \InvalidArgumentException('Permission inconnue : ' . $code . '. Importez-la d’abord.');
                    }
                }
                $create[] = $row;
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['line' => $item['line'], 'message' => $e->getMessage()];
            }
        }
        if ($errors !== [] || $dryRun) {
            return $this->json([
                'preview' => true, 'toCreate' => count($create), 'skipped' => $skipped,
                'errors' => $errors,
            ], $errors !== [] ? 422 : 200);
        }
        try {
            return $this->db->transactional(function (Connection $db) use ($kind, $create, $skipped): JsonResponse {
                foreach ($create as $row) {
                    if ($kind === 'permission') {
                        $this->insertPermission($db, $row);
                    } else {
                        $ids = $this->permissionIds($db, $row['permissions']);
                        $roleId = $this->insertRole($db, $row);
                        $this->linkPermissions($db, $roleId, $ids);
                    }
                }
                $this->audit($db, $kind === 'role' ? 'rbac_roles_imported' : 'rbac_perms_imported', [
                    'count' => count($create), 'skipped' => $skipped,
                    'codes' => array_column($create, 'code'),
                ]);
                return $this->json([
                    'createdCount' => count($create), 'skipped' => $skipped, 'codes' => array_column($create, 'code'),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Un code a été créé depuis la simulation. Réessayez l’import. Aucune ligne du lot n’a été conservée.'], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    /** @return list<array{line: int, data: array<string,string>}> */
    private function readCsv(Request $request, string $kind): array
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Fichier CSV requis (2 Mo maximum).');
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false || !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new \InvalidArgumentException('CSV UTF-8 valide requis, sans octets nuls.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $first = strtok($content, "\r\n");
        if ($first === false) throw new \InvalidArgumentException('CSV vide.');
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \InvalidArgumentException('Impossible d’ouvrir le CSV.');
        try {
            fwrite($stream, $content);
            rewind($stream);
            $header = fgetcsv($stream, 0, $delimiter, '"', '');
            if (!is_array($header)) throw new \InvalidArgumentException('En-tête CSV manquant.');
            $header = array_map(static fn (string $v): string => mb_strtolower(trim($v)), $header);
            $expected = $kind === 'role' ? ['code', 'label', 'description', 'permissions'] : ['code', 'label', 'module', 'description'];
            if (count($header) !== count(array_unique($header)) || array_diff($expected, $header) || array_diff($header, $expected)) {
                throw new \InvalidArgumentException('En-têtes requis : ' . implode(';', $expected));
            }
            $result = [];
            $line = 1;
            while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                ++$line;
                if ($values === [null]) continue;
                if (count($values) !== count($header)) {
                    throw new \InvalidArgumentException('Nombre de colonnes incorrect ligne ' . $line . '.');
                }
                if (count($result) >= self::MAX_ROWS) throw new \InvalidArgumentException('Maximum 500 lignes de données par import.');
                $result[] = ['line' => $line, 'data' => array_combine($header, $values)];
            }
            if (!$result) throw new \InvalidArgumentException('Fichier sans données.');
            return $result;
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            throw new \InvalidArgumentException('Corps JSON requis.');
        }
        try { $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('JSON invalide.'); }
        if (!is_array($data) || array_is_list($data)) throw new \InvalidArgumentException('Objet JSON requis.');
        return $data;
    }

    /** @param array<string,mixed> $data @return array{code:string,label:string,module:string,description:string} */
    private function validatePermission(array $data): array
    {
        $this->onlyKeys($data, ['code','label','module','description']);
        $code = $this->str($data, 'code');
        $label = $this->str($data, 'label');
        $module = $this->str($data, 'module');
        $description = $this->str($data, 'description', false);
        if (strlen($code) > 100 || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,3}$/D', $code)) {
            throw new \InvalidArgumentException('Code permission invalide (ex. commerces.read).');
        }
        if (!$this->textLength($label, 120, true) || !$this->textLength($module, 60, true)
            || !preg_match('/^[a-z][a-z0-9_]*$/D', $module) || !$this->textLength($description, 500)) {
            throw new \InvalidArgumentException('Libellé, module ou description invalide.');
        }
        return compact('code','label','module','description');
    }

    /** @param array<string,mixed> $data @return array{code:string,label:string,description:string,permissions:list<string>} */
    private function validateRole(array $data): array
    {
        $this->onlyKeys($data, ['code','label','description','permissions']);
        $code = strtoupper($this->str($data, 'code'));
        $label = $this->str($data, 'label');
        $description = $this->str($data, 'description', false);
        if (strlen($code) > 64 || !preg_match('/^ROLE_[A-Z0-9_]{2,59}$/D', $code)) {
            throw new \InvalidArgumentException('Code rôle invalide (ex. ROLE_CONTROLEUR).');
        }
        if (!$this->textLength($label, 120, true) || !$this->textLength($description, 500)) {
            throw new \InvalidArgumentException('Libellé ou description invalide.');
        }
        $permissions = $data['permissions'] ?? [];
        if (is_string($permissions)) {
            $permissions = trim($permissions) === '' ? [] : array_map('trim', explode('|', $permissions));
        }
        $permissions = $this->validatePermissionCodes($permissions);
        return compact('code','label','description','permissions');
    }

    /** @return list<string> */
    private function validatePermissionCodes(mixed $codes): array
    {
        if (!is_array($codes) || !array_is_list($codes) || count($codes) > 100) {
            throw new \InvalidArgumentException('Liste de permissions invalide (100 maximum).');
        }
        $seen = [];
        foreach ($codes as $code) {
            if (!is_string($code) || strlen($code) > 100 || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,3}$/D', $code)) {
                throw new \InvalidArgumentException('Code permission invalide.');
            }
            if (isset($seen[$code])) throw new \InvalidArgumentException('Permission en double : ' . $code);
            $seen[$code] = true;
        }
        return array_keys($seen);
    }

    private function str(array $data, string $key, bool $required = true): string
    {
        if (!array_key_exists($key, $data) && !$required) return '';
        if (!isset($data[$key]) || !is_string($data[$key])) throw new \InvalidArgumentException('Champ texte requis : ' . $key);
        return trim($data[$key]);
    }

    private function textLength(string $value, int $max, bool $required = false): bool
    {
        return (!$required || $value !== '') && mb_strlen($value) <= $max
            && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value);
    }

    private function onlyKeys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) throw new \InvalidArgumentException('Champs non autorisés.');
    }

    private function checkCsrf(Request $request): ?JsonResponse
    {
        $raw = $request->headers->get('X-CSRF-Token');
        if (!is_string($raw) || !$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $raw))) {
            return $this->json(['error' => 'Jeton CSRF invalide. Rechargez la page.'], 403);
        }
        return null;
    }

    /** @param list<string> $codes @return array<string,int> */
    private function permissionIds(Connection $db, array $codes): array
    {
        if ($codes === []) return [];
        $ids = [];
        foreach ($codes as $code) {
            $id = $db->fetchOne('SELECT id FROM security_permissions WHERE code = :code', ['code' => $code]);
            if ($id === false) throw new \InvalidArgumentException('Permission inconnue : ' . $code);
            $ids[$code] = (int) $id;
        }
        return $ids;
    }

    /** @param array{code:string,label:string,module:string,description:string} $data */
    private function insertPermission(Connection $db, array $data): void
    {
        $db->insert('security_permissions', [
            ...$data, 'description' => $data['description'] ?: null,
            'created_at' => gmdate('Y-m-d H:i:sP'),
        ]);
    }

    /** @param array{code:string,label:string,description:string,permissions:list<string>} $data */
    private function insertRole(Connection $db, array $data): int
    {
        return (int) $db->fetchOne(
            'INSERT INTO security_roles (code, label, description, is_system, created_at)
             VALUES (:code, :label, :description, FALSE, :created_at) RETURNING id',
            [
                'code' => $data['code'], 'label' => $data['label'],
                'description' => $data['description'] ?: null,
                'created_at' => gmdate('Y-m-d H:i:sP'),
            ],
        );
    }

    /** @param array<string,int> $permissionIds */
    private function linkPermissions(Connection $db, int $roleId, array $permissionIds): void
    {
        foreach ($permissionIds as $id) $db->insert('security_role_permissions', ['role_id' => $roleId, 'permission_id' => $id]);
    }

    /** @param array<string,mixed> $details */
    private function audit(Connection $db, string $action, array $details): void
    {
        $u = $this->getUser();
        if (!$u instanceof User || $u->getId() === null) throw $this->createAccessDeniedException();
        $db->insert('admin_user_audit', [
            'actor_user_id' => $u->getId(), 'target_user_id' => null,
            'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
