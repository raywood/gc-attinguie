<?php
declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Fixtures\MigrationAclFixture;
use App\Tests\Fixtures\MigrationExecutionFixture as Fixture;
use App\Tests\Support\IntegrationLauncher;
use App\Tests\Support\MigrationContext;
use App\Tests\Support\MigrationPostconditions;
use App\Tests\Support\MigrationProfile;
use App\Tests\Support\SecureMigrationExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Migrator;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecureMigrationExecutorTest extends TestCase
{
    /** Every DBAL call is simulated; no driver/native PDO is ever constructed. */
    private function harness(string $failure = ''): array
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getParams')->willReturn(MigrationProfile::connectionParameters());
        $level = 0;
        $events = [];
        // PHPUnit callbacks with reference captures model the outer transaction only.
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int { return $level; });
        $connection->method('isConnected')->willReturn(true);
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$level, &$events, $failure): void {
            $events[] = 'begin';
            if ($failure === 'connect') { throw new \RuntimeException('Simulated connection failure'); }
            ++$level;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$level, &$events): void { --$level; $events[] = 'rollback'; });
        $connection->method('commit')->willReturnCallback(static function () use (&$level, &$events): void { --$level; $events[] = 'commit'; });
        $native = new \stdClass();
        $nativeCalls = 0;
        $connection->method('getNativeConnection')->willReturnCallback(static function () use ($native, &$nativeCalls, $failure): object {
            return $failure === 'session' && ++$nativeCalls > 1 ? new \stdClass() : $native;
        });
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$events): int {
            if (!str_starts_with($sql, 'SET LOCAL ')) { throw new \LogicException('Unmocked write SQL'); }
            $events[] = $sql;
            return 0;
        });
        $identityReads = 0;
        $connection->method('fetchAssociative')->willReturnCallback(static function () use (&$identityReads, $failure): array {
            $row = Fixture::identity();
            ++$identityReads;
            if ($failure === 'identity' || ($failure === 'marker' && $identityReads > 1)) { $row['marker'] = 'changed'; }
            return $row;
        });
        $snapshot = Fixture::snapshot();
        $connection->method('fetchFirstColumn')->willReturnCallback(static function (string $sql) use ($snapshot, $failure): array {
            if ($sql === SecureMigrationExecutor::PUBLIC_OBJECTS_SQL) { return $failure === 'partial' ? ['users'] : []; }
            if (str_contains($sql, 'doctrine_migration_versions')) { return $failure === 'versions' ? [] : $snapshot['versions']; }
            if (str_contains($sql, 'security_roles')) { return ['ROLE_ADMIN', 'ROLE_AGENT', 'ROLE_COMPTABLE', 'ROLE_REGISSEUR']; }
            throw new \LogicException('Unmocked query');
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql) use ($snapshot): array {
            return match ($sql) {
                MigrationContext::DATABASE_ACL_SQL => MigrationAclFixture::databases(),
                MigrationContext::OBJECT_ACL_SQL => MigrationAclFixture::objects(),
                MigrationPostconditions::RELATIONS_SQL => $snapshot['relations'],
                MigrationPostconditions::CONSTRAINTS_SQL => $snapshot['constraints'],
                MigrationPostconditions::INDEXES_SQL => $snapshot['indexes'],
                MigrationPostconditions::FUNCTIONS_SQL => $snapshot['functions'],
                MigrationPostconditions::TRIGGERS_SQL => $snapshot['triggers'],
                'SELECT code, monthly_fee::text AS fee, currency FROM public.activity_types' => array_map(
                    static fn ($code, $fee): array => ['code' => $code, 'fee' => $fee, 'currency' => 'XOF'],
                    ['small_restaurant','large_restaurant','bar','maquis','bazaar','shopping_center','parking','market_stall','taxi','pharmacy','nightclub'],
                    ['5000.00','10000.00','5000.00','15000.00','20000.00','30000.00','10000.00','5000.00','10000.00','15000.00','15000.00']),
                default => throw new \LogicException('Unmocked catalogue query'),
            };
        });
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $identifier): string => '"' . $identifier . '"');
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql) use ($failure): mixed {
            if ($sql === MigrationPostconditions::EXTRA_OBJECTS_SQL) { return $failure === 'types' ? 1 : 0; }
            if (str_contains($sql, 'pg_try_advisory')) { return $failure !== 'lock'; }
            if (str_contains($sql, 'activity_tariffs')) { return 11; }
            if (str_contains($sql, 'security_roles')) { return 4; }
            if (str_contains($sql, 'WHERE id=1') || str_contains($sql, 'isolation_meta.identity')) { return 1; }
            if (str_starts_with($sql, 'SELECT count(*) FROM public.')) { return $failure === 'seed' ? 1 : 0; }
            throw new \LogicException('Unmocked count query');
        });
        $context = new MigrationContext($connection);
        $metadata = $this->createStub(MetadataStorage::class);
        $metadata->method('ensureInitialized')->willReturnCallback(static function () use (&$events): void { $events[] = 'metadata'; });
        $migrator = $this->createStub(Migrator::class);
        $migrator->method('migrate')->willReturnCallback(static function (MigrationPlanList $plan, MigratorConfiguration $config) use (&$events, $failure, &$level): array {
            self::assertSame('up', $plan->getDirection());
            self::assertCount(17, $plan->getItems());
            self::assertTrue($config->isAllOrNothing());
            self::assertFalse($config->isDryRun());
            $events[] = 'migrate';
            if ($failure === 'execution') { throw new \RuntimeException('Simulated execution failure'); }
            if ($failure === 'transaction') { ++$level; }
            return [];
        });
        // Configure doubles before Doctrine freezes its dependencies on getConnection().
        $factory = (new \ReflectionProperty(MigrationContext::class, 'doctrine'))->getValue($context);
        $factory->setDefinition(MetadataStorage::class, static fn (): MetadataStorage => $metadata);
        $factory->setDefinition(Migrator::class, static fn (): Migrator => $migrator);
        return [new SecureMigrationExecutor($context), &$events];
    }

    public function testSimulatedSuccessCommitsOnlyAfterAllChecks(): void
    {
        $harness = $this->harness();
        $harness[0]->execute(Fixture::environment());
        self::assertSame(['begin', "SET LOCAL statement_timeout = '10s'", "SET LOCAL lock_timeout = '3s'",
            "SET LOCAL idle_in_transaction_session_timeout = '10s'", 'metadata', 'migrate', 'commit'], $harness[1]);
    }

    #[DataProvider('failures')]
    public function testFailureNeverCommitsOrRepairs(string $failure, bool $beforeWrites): void
    {
        $harness = $this->harness($failure);
        try {
            $harness[0]->execute(Fixture::environment());
            self::fail('Unsafe execution accepted');
        } catch (\RuntimeException $exception) {
            self::assertNotContains('commit', $harness[1]);
            if ($failure !== 'connect') { self::assertContains('rollback', $harness[1]); }
            if ($beforeWrites) { self::assertNotContains('metadata', $harness[1]); self::assertNotContains('migrate', $harness[1]); }
            elseif ($failure !== 'connect') { self::assertContains('metadata', $harness[1]); self::assertContains('migrate', $harness[1]); }
            self::assertNotSame('', $exception->getMessage());
        }
    }

    public static function failures(): iterable
    {
        foreach (['connect', 'identity', 'partial', 'lock', 'session'] as $case) { yield $case => [$case, true]; }
        foreach (['execution', 'transaction', 'marker', 'versions', 'seed', 'types'] as $case) { yield $case => [$case, false]; }
    }

    public function testInvalidEnvironmentNeverBeginsConnection(): void
    {
        $harness = $this->harness();
        $environment = Fixture::environment();
        $environment['APP_ENV'] = 'prod';
        try { $harness[0]->execute($environment); self::fail('Accepted'); }
        catch (\LogicException) { self::assertSame([], $harness[1]); }
    }

    public function testIncompleteRepositoryRejectedOffline(): void
    {
        $context = MigrationContext::create(Fixture::environment());
        $available = $context->doctrine()->getMigrationRepository()->getMigrations()->getItems();
        $this->expectException(\LogicException::class);
        SecureMigrationExecutor::approvedPlan(array_slice($available, 1), $context->doctrine()->getConnection());
    }

    public function testSubstitutedMigrationConnectionRejectedOffline(): void
    {
        $context = MigrationContext::create(Fixture::environment());
        $other = MigrationContext::create(Fixture::environment());
        $this->expectException(\LogicException::class);
        SecureMigrationExecutor::approvedPlan($context->doctrine()->getMigrationRepository()->getMigrations()->getItems(), $other->doctrine()->getConnection());
    }

    public function testPrepareAndResetNeverReachTheExecutorOrAnyProbe(): void
    {
        $fail = static function (): never { throw new \RuntimeException('Unexpected connection'); };
        foreach (['prepare', 'reset'] as $command) {
            try { (new IntegrationLauncher($fail, $fail))->run($command, Fixture::environment()); self::fail('Accepted'); }
            catch (\LogicException $exception) { self::assertStringContainsString('disabled', $exception->getMessage()); }
        }
    }
}
