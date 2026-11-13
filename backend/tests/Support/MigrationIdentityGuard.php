<?php
declare(strict_types=1);

namespace App\Tests\Support;

final class MigrationIdentityGuard
{
    public static function assertSafe(array $row): void
    {
        self::assertIdentity($row);
        MigrationAclPolicy::assertSafe($row['databases'] ?? [], $row['object_acls'] ?? []);
    }

    /** Identity/marker first, before collecting the detailed ACL snapshot. */
    public static function assertIdentity(array $row): void
    {
        foreach ([
            'database_name' => 'gc_attinguie_test', 'user_name' => 'gc_test_migrator',
            'session_user_name' => 'gc_test_migrator', 'current_schema' => 'public',
            'search_path' => 'public', 'marker' => 'gc-attinguie-isolated-test-v1',
            'marker_owner' => 'gc_test_admin', 'schema_owner' => 'gc_test_admin',
            'marker_kind' => 'r',
        ] as $key => $value) {
            if (($row[$key] ?? null) !== $value) {
                throw new \LogicException('Unsafe migration identity: ' . $key);
            }
        }
        if (!is_scalar($row['server_version_num'] ?? null)
            || !preg_match('/^16\d{4}$/D', (string) $row['server_version_num'])) {
            throw new \LogicException('Migration requires PostgreSQL 16.');
        }
        foreach (['elevated_role', 'other_role_member', 'marker_writable', 'schema_writable',
            'marker_owner_member', 'database_owner_member', 'public_owner_member',
            'database_create', 'unexpected_direct_grants', 'other_schema_write', 'marker_column_acl'] as $key) {
            if (!array_key_exists($key, $row) || !in_array($row[$key], [false, 'f', '0', 0], true)) {
                throw new \LogicException('Unsafe migration privilege: ' . $key);
            }
        }
        foreach (['public_usage', 'public_create', 'plpgsql_usage'] as $key) {
            if (!in_array($row[$key] ?? null, [true, 't', '1', 1], true)) {
                throw new \LogicException('Missing migration privilege: ' . $key);
            }
        }
    }
}
