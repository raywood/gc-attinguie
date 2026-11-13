<?php
declare(strict_types=1);

namespace App\Tests\Support;

/** Validates a snapshot; no database access or retained authorization state. */
final class DatabaseIdentityGuard
{
    /** @param array<string, mixed> $identity */
    public static function assertSafe(array $identity): void
    {
        foreach ([
            'database_name' => 'gc_attinguie_test',
            'user_name' => 'gc_test_runner',
            'session_user_name' => 'gc_test_runner',
            'marker' => 'gc-attinguie-isolated-test-v1',
            'marker_owner' => 'gc_test_admin',
            'schema_owner' => 'gc_test_admin',
        ] as $key => $expected) {
            if (($identity[$key] ?? null) !== $expected) {
                throw new \LogicException('Unsafe PostgreSQL identity: ' . $key);
            }
        }
        $version = $identity['server_version_num'] ?? null;
        if ((!is_int($version) && !is_string($version))
            || !preg_match('/^16\d{4}$/D', (string) $version)) {
            throw new \LogicException('PostgreSQL 16 required.');
        }
        foreach (['elevated_role', 'marker_writable', 'schema_writable', 'marker_owner_member'] as $key) {
            if (!array_key_exists($key, $identity) || !in_array($identity[$key], [false, 'f', '0', 0], true)) {
                throw new \LogicException('Unsafe PostgreSQL privileges: ' . $key);
            }
        }
    }
}
