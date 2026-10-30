<?php
declare(strict_types=1);
namespace App\Security;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Source de vérité des habilitations : compte actif et rôles lus en base à chaque requête. */
final class MunicipalPermissionResolver
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{active:bool,roles:list<string>,permissions:list<string>,admin:bool} */
    public function forUserId(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT roles, is_active FROM users WHERE id = :id', ['id' => $id]);
        if ($row === false || !in_array($row['is_active'], [true, 1, '1', 't', 'true'], true)) {
            return ['active' => false, 'roles' => [], 'permissions' => [], 'admin' => false];
        }
        $decoded = json_decode((string) $row['roles'], true);
        $roles = is_array($decoded) && array_is_list($decoded)
            ? array_values(array_unique(array_filter($decoded, static fn ($r): bool => is_string($r) && preg_match('/^ROLE_[A-Z0-9_]{2,59}$/D', $r) === 1)))
            : [];
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);
        if ($isAdmin) {
            // ROLE_ADMIN reste un super-administrateur système, indépendamment du catalogue.
            $permissions = $this->db->fetchFirstColumn('SELECT code FROM security_permissions ORDER BY code');
        } elseif ($roles !== []) {
            $permissions = $this->db->fetchFirstColumn(<<<'SQL'
SELECT DISTINCT p.code
  FROM security_permissions p
  JOIN security_role_permissions rp ON rp.permission_id = p.id
  JOIN security_roles r ON r.id = rp.role_id
 WHERE r.code IN (:roles)
 ORDER BY p.code
SQL, ['roles' => $roles], ['roles' => ArrayParameterType::STRING]);
        } else {
            $permissions = [];
        }
        return ['active' => true, 'roles' => $roles, 'permissions' => array_values($permissions), 'admin' => $isAdmin];
    }

    public function allows(int $id, string $permission): bool
    {
        $grants = $this->forUserId($id);
        return $grants['active'] && ($grants['admin'] || in_array($permission, $grants['permissions'], true));
    }
}
