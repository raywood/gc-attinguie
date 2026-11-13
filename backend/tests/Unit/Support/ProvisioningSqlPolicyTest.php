<?php
declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\ProvisioningSqlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProvisioningSqlPolicyTest extends TestCase
{
    private static function script(): string
    {
        return file_get_contents(dirname(__DIR__, 4) . '/docker/test/provision-test-migrator.sql');
    }

    public function testScriptOnlyConfiguresNewMigrator(): void
    {
        ProvisioningSqlPolicy::assertSafe(self::script());
        self::assertTrue(true);
    }

    public function testGuardBoundsPublicPrivilegesBeforeRoleCreation(): void
    {
        $sql = self::script();
        self::assertStringContainsString("acldefault('d', d.datdba)", $sql);
        self::assertStringContainsString('WHERE a.grantee = 0', $sql);
        self::assertStringContainsString("d.datname = 'gc_attinguie_test' AND a.privilege_type = 'TEMPORARY'", $sql);
        self::assertStringContainsString("d.datname = 'postgres' AND a.privilege_type IN ('CONNECT', 'TEMPORARY')", $sql);
        self::assertStringContainsString("a.privilege_type = 'CONNECT'", $sql);
        self::assertStringContainsString('a.is_grantable OR a.grantor <> d.datdba', $sql);
        self::assertStringContainsString("a.privilege_type <> 'USAGE'", $sql);
        self::assertStringContainsString("n.nspname NOT IN ('information_schema','public','isolation_meta')", $sql);
        self::assertStringContainsString("d.datallowconn IS DISTINCT FROM (d.datname <> 'template0')", $sql);
        self::assertLessThan(strpos($sql, 'CREATE ROLE gc_test_migrator'), strpos($sql, 'PUBLIC database ACL exceeds'));
        self::assertStringContainsString("WHERE rolname = 'gc_test_migrator'", $sql);
    }

    #[DataProvider('forbiddenChanges')]
    public function testDirectAndIndirectPrivilegeChangesAreRejected(string $statement): void
    {
        $sql = str_replace('COMMIT;', $statement . "\nCOMMIT;", self::script());
        $this->expectException(\LogicException::class);
        ProvisioningSqlPolicy::assertSafe($sql);
    }

    public static function forbiddenChanges(): iterable
    {
        yield 'runner revoke' => ['REVOKE CREATE ON SCHEMA public FROM gc_test_runner;'];
        yield 'PUBLIC revoke' => ['REVOKE CONNECT ON DATABASE postgres FROM PUBLIC;'];
        yield 'PUBLIC grant' => ['GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO PUBLIC;'];
        yield 'inherited membership' => ['GRANT gc_test_migrator TO gc_test_runner;'];
        yield 'membership to migrator' => ['GRANT gc_test_admin TO gc_test_migrator;'];
        yield 'ownership' => ['ALTER SCHEMA isolation_meta OWNER TO gc_test_migrator;'];
        yield 'default privileges' => ['ALTER DEFAULT PRIVILEGES FOR ROLE gc_test_migrator REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;'];
        yield 'another default role' => ['ALTER DEFAULT PRIVILEGES FOR ROLE gc_test_admin GRANT SELECT ON TABLES TO gc_test_runner;'];
        yield 'runner setting' => ["ALTER ROLE gc_test_runner SET search_path = public;"];
        yield 'direct catalog update' => ["UPDATE pg_catalog.pg_database SET datacl = NULL;"];
        yield 'dynamic SQL' => ["DO \$other\$ BEGIN EXECUTE 'REVOKE CREATE ON SCHEMA public FROM gc_test_runner'; END \$other\$;"];
        yield 'procedure call' => ['CALL change_existing_acl();'];
        yield 'psql include' => ['\\i another-script.sql'];
        yield 'elevated migrator' => ['ALTER ROLE gc_test_migrator SUPERUSER;'];
        yield 'delegation' => ['GRANT CONNECT ON DATABASE gc_attinguie_test TO gc_test_migrator WITH GRANT OPTION;'];
        yield 'system database direct grant' => ['GRANT CONNECT ON DATABASE postgres TO gc_test_migrator;'];
        yield 'indirect runner access' => ['GRANT SELECT ON isolation_meta.identity TO PUBLIC;'];
        yield 'delegation on marker' => ['GRANT SELECT ON isolation_meta.identity TO gc_test_migrator WITH GRANT OPTION;'];
        yield 'extra default function rights' => ['ALTER DEFAULT PRIVILEGES FOR ROLE gc_test_migrator GRANT EXECUTE ON FUNCTIONS TO PUBLIC;'];
    }

    #[DataProvider('guardInjections')]
    public function testGuardCannotHideIndirectSql(string $code): void
    {
        $sql = str_replace('DO $guard$' . "\nBEGIN", 'DO $guard$' . "\nBEGIN\n" . $code, self::script());
        $this->expectException(\LogicException::class);
        ProvisioningSqlPolicy::assertSafe($sql);
    }

    public static function guardInjections(): iterable
    {
        yield ['REVOKE CREATE ON SCHEMA public FROM gc_test_runner;'];
        yield ["EXECUTE 'GRANT gc_test_admin TO gc_test_runner';"];
        yield ["PERFORM set_config('role','gc_test_admin',true);"];
        yield ['SELECT dangerous_acl_function();'];
        yield ['SELECT public.aclexplode(NULL);'];
        yield ['CALL change_existing_acl();'];
        yield ['SELECT 1 INTO unexpected_table;'];
        yield ['/* harmless comment */ ALTER ROLE gc_test_runner SUPERUSER;'];
    }
}
