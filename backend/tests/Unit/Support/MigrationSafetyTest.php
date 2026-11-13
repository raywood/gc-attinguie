<?php
declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Fixtures\MigrationAclFixture;
use App\Tests\Support\DatabaseIdentityGuard;
use App\Tests\Support\IntegrationLauncher;
use App\Tests\Support\MigrationContext;
use App\Tests\Support\MigrationIdentityGuard;
use App\Tests\Support\MigrationPlan;
use App\Tests\Support\MigrationProfile;
use App\Tests\Support\TestEnvironmentGuard;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationSafetyTest extends TestCase
{
    private static function environment(): array
    {
        return ['GC_TEST_PROFILE' => 'migration', 'APP_ENV' => 'test', 'APP_DEBUG' => '0',
            'DATABASE_URL' => MigrationProfile::DATABASE_URL,
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://', 'TEST_TOKEN' => ''];
    }

    private static function identity(): array
    {
        return ['database_name' => 'gc_attinguie_test', 'user_name' => 'gc_test_migrator',
            'session_user_name' => 'gc_test_migrator', 'current_schema' => 'public', 'search_path' => 'public',
            'marker' => 'gc-attinguie-isolated-test-v1', 'marker_owner' => 'gc_test_admin',
            'schema_owner' => 'gc_test_admin', 'marker_kind' => 'r', 'server_version_num' => '160016',
            'elevated_role' => false, 'other_role_member' => false, 'marker_writable' => false,
            'schema_writable' => false, 'marker_owner_member' => false, 'database_owner_member' => false,
            'public_owner_member' => false, 'database_create' => false,
            'unexpected_direct_grants' => false, 'other_schema_write' => false, 'marker_column_acl' => false,
            'databases' => MigrationAclFixture::databases(), 'object_acls' => MigrationAclFixture::objects(),
            'public_usage' => true, 'public_create' => true, 'plpgsql_usage' => true];
    }

    public function testOfflineNeverInvokesEitherProbe(): void
    {
        $fail = static function (): never { throw new \RuntimeException('Unexpected probe'); };
        $launcher = new IntegrationLauncher($fail, $fail);
        self::assertStringContainsString('17 pinned sources', $launcher->run('check-migration-offline', self::environment()));
    }

    #[DataProvider('badEnvironment')]
    public function testWrongProfileRejectedBeforeAnyProbe(string $key, mixed $value): void
    {
        $env = self::environment();
        $env[$key] = $value;
        $fail = static function (): never { throw new \RuntimeException('Unexpected probe'); };
        $this->expectException(\LogicException::class);
        (new IntegrationLauncher($fail, $fail))->run('check-migration-identity', $env);
    }

    public static function badEnvironment(): iterable
    {
        yield ['APP_ENV', 'prod'];
        yield ['APP_ENV', 'dev'];
        yield ['APP_DEBUG', '1'];
        yield ['GC_TEST_PROFILE', null];
        yield ['GC_TEST_PROFILE', 'runner'];
        yield ['DATABASE_URL', TestEnvironmentGuard::DATABASE_URL];
        foreach (['@db-test:' => '@production:', '/gc_attinguie_test?' => '/production?',
            'gc_test_migrator:' => 'gc_test_admin:', ':5432/' => ':5433/'] as $from => $to) {
            yield ['DATABASE_URL', str_replace($from, $to, MigrationProfile::DATABASE_URL)];
        }
        yield ['DATABASE_URL', MigrationProfile::DATABASE_URL . '&host=production'];
        yield ['MESSENGER_TRANSPORT_DSN', 'redis://redis'];
        yield ['TEST_TOKEN', '1'];
    }

    public function testRunnerIdentityRemainsStrict(): void
    {
        $this->expectException(\LogicException::class);
        DatabaseIdentityGuard::assertSafe(self::identity());
    }

    public function testProfileCannotCallRunnerCommand(): void
    {
        $this->expectException(\LogicException::class);
        (new IntegrationLauncher(static fn (): array => []))->run('check-identity', self::environment());
    }

    public function testValidMigrationSnapshot(): void
    {
        MigrationIdentityGuard::assertSafe(self::identity());
        self::assertTrue(true);
    }

    #[DataProvider('badIdentity')]
    public function testUnsafeSnapshotRejected(string $key, mixed $value): void
    {
        $row = self::identity();
        $row[$key] = $value;
        $this->expectException(\LogicException::class);
        MigrationIdentityGuard::assertSafe($row);
    }

    public static function badIdentity(): iterable
    {
        yield ['database_name', 'production'];
        yield ['user_name', 'gc_test_runner'];
        yield ['session_user_name', 'gc_test_admin'];
        yield ['server_version_num', '170001'];
        yield ['current_schema', 'isolation_meta'];
        yield ['search_path', 'public,isolation_meta'];
        yield ['marker', 'wrong'];
        yield ['marker_owner', 'gc_test_migrator'];
        yield ['schema_owner', 'gc_test_migrator'];
        yield ['marker_kind', 'v'];
        foreach (['elevated_role', 'other_role_member', 'marker_writable', 'schema_writable',
            'marker_owner_member', 'database_owner_member', 'public_owner_member',
            'database_create', 'unexpected_direct_grants', 'other_schema_write', 'marker_column_acl'] as $key) {
            yield [$key, true];
            yield [$key, null];
        }
        foreach (['public_usage', 'public_create', 'plpgsql_usage'] as $key) {
            yield [$key, false];
        }
    }

    public function testSameOfflineConnectionIsUsedByDoctrine(): void
    {
        $context = MigrationContext::create(self::environment());
        $connection = $context->doctrine()->getConnection();
        self::assertFalse($connection->isConnected());
        self::assertSame(MigrationProfile::connectionParameters(), $connection->getParams());
        self::assertSame($connection, $context->doctrine()->getConnection());
        self::assertTrue($context->doctrine()->getConfiguration()->isAllOrNothing());
    }

    public function testSubstitutedConnectionRejectedEvenWithIdenticalParameters(): void
    {
        $context = MigrationContext::create(self::environment());
        $other = MigrationContext::create(self::environment())->doctrine()->getConnection();
        $this->expectException(\LogicException::class);
        $context->assertConnection($other);
    }

    public function testWrongDbalParametersRejectedWithoutConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['dbname' => 'production']);
        $connection->expects(self::never())->method('getNativeConnection');
        $this->expectException(\LogicException::class);
        new MigrationContext($connection);
    }

    private function connectionMock(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(MigrationProfile::connectionParameters());
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql): array {
            $rows = match ($sql) {
                MigrationContext::DATABASE_ACL_SQL => MigrationAclFixture::databases(),
                MigrationContext::OBJECT_ACL_SQL => MigrationAclFixture::objects(),
                default => throw new \LogicException('Unexpected simulated SQL'),
            };
            // PDO/DBAL returns JSON strings, not pre-decoded arrays.
            foreach ($rows as &$row) {
                foreach (['public_acl', 'direct_acl'] as $key) {
                    $row[$key] = json_encode($row[$key], JSON_THROW_ON_ERROR);
                }
            }
            return $rows;
        });
        $connection->expects(self::never())->method('getNativeConnection');
        return $connection;
    }

    public function testCompetingExecutionRefusedAndTransactionReleased(): void
    {
        $connection = $this->connectionMock();
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('fetchOne')->willReturn(false);
        $connection->expects(self::never())->method('fetchAssociative');
        $connection->expects(self::once())->method('rollBack');
        $this->expectException(\LogicException::class);
        (new MigrationContext($connection))->checkIdentity(self::environment());
    }

    public function testOnlinePreflightIsSimulatedWithBoundedSettingsAndEmptySchema(): void
    {
        $connection = $this->connectionMock();
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;
            return 0;
        });
        $connection->method('fetchOne')->willReturn(true);
        $connection->method('fetchAssociative')->willReturn(self::identity());
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        self::assertStringContainsString('writes disabled', (new MigrationContext($connection))->checkIdentity(self::environment()));
        self::assertSame(['SET TRANSACTION READ ONLY', "SET LOCAL statement_timeout = '10s'",
            "SET LOCAL lock_timeout = '3s'", "SET LOCAL idle_in_transaction_session_timeout = '10s'"], $statements);
    }

    public function testInvalidIdentityReleasesLockWithoutSchemaInspection(): void
    {
        $connection = $this->connectionMock();
        $connection->method('fetchOne')->willReturn(true);
        $connection->method('fetchAssociative')->willReturn([]);
        $connection->expects(self::never())->method('fetchFirstColumn');
        $connection->expects(self::once())->method('rollBack');
        $this->expectException(\LogicException::class);
        (new MigrationContext($connection))->checkIdentity(self::environment());
    }

    public function testExistingSchemaRefusedWithoutAutomaticRepair(): void
    {
        $connection = $this->connectionMock();
        $connection->method('fetchOne')->willReturn(true);
        $connection->method('fetchAssociative')->willReturn(self::identity());
        $connection->method('fetchFirstColumn')->willReturn(['doctrine_migration_versions']);
        $connection->expects(self::once())->method('rollBack');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not empty');
        (new MigrationContext($connection))->checkIdentity(self::environment());
    }

    public function testNoProbeConfiguredFailsClosed(): void
    {
        $launcher = new IntegrationLauncher(static fn (): array => []);
        $this->expectException(\LogicException::class);
        $launcher->run('check-migration-identity', self::environment());
    }

    public function testArbitraryDoctrineCommandIsNotForwarded(): void
    {
        $launcher = new IntegrationLauncher(static function (): never { throw new \RuntimeException('Unexpected probe'); });
        $this->expectException(\LogicException::class);
        $launcher->run('doctrine:migrations:migrate', self::environment());
    }

    #[DataProvider('sensitiveCommands')]
    public function testSensitiveCommandsRemainDisabledAfterSimulatedSuccess(string $command): void
    {
        $calls = 0;
        $launcher = new IntegrationLauncher(static fn (): array => [], static function () use (&$calls): string {
            ++$calls;
            return 'simulated identity success';
        });
        $launcher->run('check-migration-identity', self::environment());
        try {
            $launcher->run($command, self::environment());
            self::fail('Sensitive command accepted');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('disabled', $exception->getMessage());
            self::assertSame(1, $calls);
        }
    }

    public static function sensitiveCommands(): iterable
    {
        yield ['prepare'];
        yield ['reset'];
    }

    public function testApprovedManifest(): void
    {
        $manifest = MigrationPlan::inspect();
        self::assertCount(17, $manifest['migrations']);
        self::assertCount(22, $manifest['tables']);
        self::assertCount(3, array_filter(array_column($manifest['migrations'], 'dropped_indexes')));
    }

    #[DataProvider('badManifest')]
    public function testUnexpectedPlanRejected(string $mutation): void
    {
        $manifest = MigrationPlan::manifest();
        match ($mutation) {
            'version' => $manifest['migrations'][0]['version'] = '20990101000000',
            'hash' => $manifest['migrations'][0]['sha256'] = str_repeat('0', 64),
            'drop' => $manifest['migrations'][0]['dropped_indexes'] = ['unknown'],
        };
        $this->expectException(\LogicException::class);
        MigrationPlan::inspect($manifest);
    }

    public static function badManifest(): iterable
    {
        yield ['version']; yield ['hash']; yield ['drop'];
    }

    public function testPartialInitialStateRejected(): void
    {
        $this->expectException(\LogicException::class);
        MigrationPlan::assertInitialState(['receipts'], []);
    }

    public function testMetadataWithoutEmptyStateRejected(): void
    {
        $this->expectException(\LogicException::class);
        MigrationPlan::assertInitialState([], ['DoctrineMigrations\\Version20261026120000']);
    }

    public function testCompletedSnapshotMustMatchAllTablesAndVersions(): void
    {
        $tables = [...MigrationPlan::manifest()['tables'], 'doctrine_migration_versions'];
        $versions = array_map(static fn (string $v): string => 'DoctrineMigrations\\Version' . $v, MigrationPlan::VERSIONS);
        MigrationPlan::assertCompletedState($tables, $versions);
        self::assertCount(23, $tables);
        $this->expectException(\LogicException::class);
        MigrationPlan::assertCompletedState($tables, array_slice($versions, 1));
    }
}
