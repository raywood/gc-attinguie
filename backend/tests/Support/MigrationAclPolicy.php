<?php
declare(strict_types=1);

namespace App\Tests\Support;

/** Explicit upper bounds from audit 10B.2B; no new grants are inferred. */
final class MigrationAclPolicy
{
    public const DATABASE_PUBLIC = [
        'gc_attinguie_test' => ['TEMPORARY'],
        'postgres' => ['CONNECT', 'TEMPORARY'],
        'template0' => ['CONNECT'],
        'template1' => ['CONNECT'],
    ];

    public static function assertSafe(array $databases, array $objects): void
    {
        $seen = [];
        foreach ($databases as $row) {
            $name = $row['name'] ?? '';
            if (!is_string($name) || !isset(self::DATABASE_PUBLIC[$name]) || isset($seen[$name])
                || ($row['owner'] ?? null) !== 'gc_test_admin'
                || self::boolean($row['allow_connections'] ?? null) !== ($name !== 'template0')) {
                throw new \LogicException('Unexpected database identity or connection policy.');
            }
            $seen[$name] = true;
            $public = self::privileges($row['public_acl'] ?? null, self::DATABASE_PUBLIC[$name], 'gc_test_admin');
            $direct = self::privileges($row['direct_acl'] ?? null, $name === 'gc_attinguie_test' ? ['CONNECT'] : [], 'gc_test_admin');
            if ($name === 'gc_attinguie_test' && $direct !== ['CONNECT']) {
                throw new \LogicException('Dedicated migration CONNECT grant required.');
            }
            // Effective rights must come exclusively from the inspected direct/PUBLIC ACLs.
            // No connection is attempted to any other database, including templates.
            foreach (['CONNECT', 'TEMPORARY', 'CREATE'] as $privilege) {
                $expected = in_array($privilege, [...$public, ...$direct], true);
                if (self::boolean($row['effective_' . strtolower($privilege)] ?? null) !== $expected) {
                    throw new \LogicException('Unexpected effective database privilege.');
                }
            }
        }
        if (count($seen) !== count(self::DATABASE_PUBLIC)) {
            throw new \LogicException('Incomplete database inventory.');
        }

        $allowed = [
            'schema:public' => ['pg_database_owner', ['USAGE'], ['CREATE', 'USAGE']],
            'schema:isolation_meta' => ['gc_test_admin', [], ['USAGE']],
            'table:isolation_meta.identity' => ['gc_test_admin', [], ['SELECT']],
            'language:plpgsql' => ['gc_test_admin', ['USAGE'], ['USAGE']],
        ];
        $seen = [];
        foreach ($objects as $row) {
            $name = $row['resource'] ?? '';
            if (!is_string($name) || !isset($allowed[$name]) || isset($seen[$name])) {
                throw new \LogicException('Unexpected ACL resource.');
            }
            $seen[$name] = true;
            [$owner, $publicBudget, $directBudget] = $allowed[$name];
            if (($row['owner'] ?? null) !== $owner) {
                throw new \LogicException('Unexpected ACL resource owner.');
            }
            self::privileges($row['public_acl'] ?? null, $publicBudget, $owner);
            $direct = self::privileges($row['direct_acl'] ?? null, $directBudget, $owner);
            sort($directBudget);
            if ($direct !== $directBudget) {
                throw new \LogicException('Dedicated migration object grants differ from provisioning policy.');
            }
        }
        if (count($seen) !== count($allowed)) {
            throw new \LogicException('Incomplete object ACL inventory.');
        }
    }

    private static function privileges(mixed $acl, array $allowed, string $grantor): array
    {
        if (!is_array($acl) || !array_is_list($acl)) {
            throw new \LogicException('Missing ACL details.');
        }
        $seen = [];
        foreach ($acl as $entry) {
            $privilege = $entry['privilege'] ?? null;
            if (!is_string($privilege) || !in_array($privilege, $allowed, true) || isset($seen[$privilege])
                || ($entry['grantor'] ?? null) !== $grantor || self::boolean($entry['grantable'] ?? null)) {
                throw new \LogicException('Unexpected direct/PUBLIC grant or delegation option.');
            }
            $seen[$privilege] = true;
        }
        $privileges = array_keys($seen);
        sort($privileges);
        return $privileges;
    }

    public static function boolean(mixed $value): bool
    {
        if (in_array($value, [true, 't', '1', 1], true)) {
            return true;
        }
        if (in_array($value, [false, 'f', '0', 0], true)) {
            return false;
        }
        throw new \LogicException('Missing or invalid PostgreSQL boolean.');
    }
}
