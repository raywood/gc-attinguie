<?php
declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Tests\Support\MigrationPlan;
use App\Tests\Support\MigrationPostconditions;
use App\Tests\Support\MigrationProfile;

final class MigrationExecutionFixture
{
    public static function environment(): array
    {
        return ['GC_TEST_PROFILE' => 'migration', 'APP_ENV' => 'test', 'APP_DEBUG' => '0',
            'DATABASE_URL' => MigrationProfile::DATABASE_URL, 'MESSENGER_TRANSPORT_DSN' => 'in-memory://', 'TEST_TOKEN' => ''];
    }

    public static function identity(): array
    {
        return ['database_name' => 'gc_attinguie_test', 'user_name' => 'gc_test_migrator',
            'session_user_name' => 'gc_test_migrator', 'current_schema' => 'public', 'search_path' => 'public',
            'marker' => 'gc-attinguie-isolated-test-v1', 'marker_owner' => 'gc_test_admin', 'schema_owner' => 'gc_test_admin',
            'marker_kind' => 'r', 'server_version_num' => '160015',
            ...array_fill_keys(['elevated_role', 'other_role_member', 'marker_writable', 'schema_writable',
                'marker_owner_member', 'database_owner_member', 'public_owner_member', 'database_create',
                'unexpected_direct_grants', 'other_schema_write', 'marker_column_acl'], false),
            'public_usage' => true, 'public_create' => true, 'plpgsql_usage' => true,
            'databases' => MigrationAclFixture::databases(), 'object_acls' => MigrationAclFixture::objects()];
    }

    /** Synthetic catalogue, not evidence that PostgreSQL generated these definitions. */
    public static function snapshot(): array
    {
        $spec = MigrationPostconditions::specification();
        $tables = [...MigrationPlan::manifest()['tables'], 'doctrine_migration_versions'];
        $relations = [];
        $relation = static fn (string $name, string $kind): array => ['name' => $name, 'kind' => $kind,
            'owner' => 'gc_test_migrator', 'persistence' => 'p', 'unsafe' => false];
        foreach ($tables as $table) {
            $relations[] = $relation($table, 'r');
            if (!in_array($table, ['app_branding', 'doctrine_migration_versions'], true)) {
                $relations[] = $relation($table . '_id_seq', 'S');
            }
        }
        $indexes = [];
        foreach ($spec['indexes'] as $name => $entry) {
            $relations[] = $relation($name, 'i');
            $indexes[] = ['name' => $name, ...$entry, 'valid' => true, 'ready' => true, 'method' => 'btree', 'unsafe' => false];
        }
        $constraints = [];
        foreach ($spec['constraints'] as $name => $entry) {
            $constraints[] = ['name' => $name, ...$entry, 'validated' => true, 'deferrable' => false,
                'target_schema' => 'public', 'update_action' => 'a',
                'columns' => $entry['columns'] ?? (str_starts_with($name, 'doctrine_migration_versions.') ? 'version' : 'id')];
        }
        preg_match('/AS \$body\$(.*?)\$body\$ LANGUAGE plpgsql/s', file_get_contents(dirname(__DIR__, 2) . '/migrations/Version20261027220000.php'), $body);
        return ['relations' => $relations, 'constraints' => $constraints, 'indexes' => $indexes,
            'versions' => array_map(static fn (string $v): string => 'DoctrineMigrations\\Version' . $v, MigrationPlan::VERSIONS),
            'functions' => [['name' => 'gc_guard_receipts', 'owner' => 'gc_test_migrator', 'language' => 'plpgsql',
                'result' => 'trigger', 'arguments' => '0', 'body' => $body[1], 'unsafe' => false]],
            'triggers' => [['name' => 'gc_receipts_no_update', 'table' => 'receipts', 'function' => 'gc_guard_receipts',
                'function_schema' => 'public', 'enabled' => 'O', 'type' => '27', 'qualifier' => null]]];
    }
}
