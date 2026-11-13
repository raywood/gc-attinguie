<?php
declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Metadata\MigrationPlan as Step;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;

/** Future write mechanism. Deliberately NOT reachable from the CLI prepare command. */
final class SecureMigrationExecutor
{
    private bool $used = false;

    public function __construct(private readonly MigrationContext $context) {}

    /** Actual CLI entry gate: no context, connection or authorization flag is accepted. */
    public static function prepare(array $environment): never
    {
        MigrationProfile::assertSafe($environment);
        MigrationPlan::inspect();
        MigrationPostconditions::specification();
        throw new \LogicException('Sensitive operation disabled: migration engine awaits a separately authorized mission.');
    }

    public function execute(array $environment): void
    {
        MigrationProfile::assertSafe($environment);
        MigrationPlan::inspect(); // Before even opening the lazy DBAL connection.
        MigrationPostconditions::specification();
        if ($this->used) {
            throw new \LogicException('Migration executor is single-use; no automatic retry.');
        }
        $this->used = true;
        $factory = $this->context->doctrine();
        $connection = $factory->getConnection();
        $this->context->assertConnection($connection);
        $plan = self::approvedPlan($factory->getMigrationRepository()->getMigrations()->getItems(), $connection);
        if ($connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Unexpected existing transaction.');
        }
        $connection->beginTransaction();
        try {
            $connection->executeStatement("SET LOCAL statement_timeout = '10s'");
            $connection->executeStatement("SET LOCAL lock_timeout = '3s'");
            $connection->executeStatement("SET LOCAL idle_in_transaction_session_timeout = '10s'");
            if (!in_array($connection->fetchOne('SELECT pg_try_advisory_xact_lock(826043, 1)'), [true, 't', '1', 1], true)) {
                throw new \LogicException('Migration lock unavailable.');
            }
            $identity = MigrationPostconditions::identity($connection);
            MigrationIdentityGuard::assertSafe($identity);
            if ((int) $connection->fetchOne('SELECT count(*) FROM isolation_meta.identity') !== 1) {
                throw new \LogicException('Unexpected marker cardinality.');
            }
            MigrationPlan::assertInitialState($connection->fetchFirstColumn(self::PUBLIC_OBJECTS_SQL), []);
            $native = $connection->getNativeConnection();
            $this->assertSession($connection, $native);
            MigrationPlan::inspect(); // Recheck sources immediately before the first DDL.
            // This is a WRITE, unlike repository inspection. Never use the console dry-run.
            $factory->getMetadataStorage()->ensureInitialized();
            $this->assertSession($connection, $native);
            $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true)->setDryRun(false));
            $this->assertSession($connection, $native);
            MigrationPlan::inspect();
            MigrationPostconditions::verify($connection, $identity);
            $this->assertSession($connection, $native);
            $connection->commit(); // Metadata, all migrations and checks belong to this transaction.
        } catch (\Throwable $failure) {
            // No repair, retry, down migration, purge or second connection.
            // A lost session has already lost its transaction; never reconnect to roll back.
            if ($connection->isConnected() && $connection->getTransactionNestingLevel() > 0) {
                try {
                    while ($connection->getTransactionNestingLevel() > 0) {
                        $connection->rollBack();
                    }
                } catch (\Throwable) {
                    $connection->close();
                }
            }
            throw new \RuntimeException('Guarded migration failed; no automatic repair. Verify test instance before retry.', 0, $failure);
        }
    }

    private function assertSession(Connection $connection, mixed $native): void
    {
        $this->context->assertConnection($connection);
        if (!$connection->isConnected() || $connection->getNativeConnection() !== $native
            || $connection->getTransactionNestingLevel() !== 1 || $connection->isRollbackOnly()) {
            throw new \LogicException('Migration session or outer transaction changed.');
        }
    }

    /** Builds only the complete, ordered UP plan; never invokes migration up()/down(). */
    public static function approvedPlan(array $available, Connection $connection): MigrationPlanList
    {
        MigrationPlan::inspect();
        $steps = [];
        foreach ($available as $item) {
            $version = (string) $item->getVersion();
            if (isset($steps[$version])) {
                throw new \LogicException('Duplicate migration.');
            }
            $migration = $item->getMigration();
            $bound = (new \ReflectionProperty(\Doctrine\Migrations\AbstractMigration::class, 'connection'))->getValue($migration);
            $file = (new \ReflectionClass($migration))->getFileName();
            $expectedFile = dirname(__DIR__, 2) . '/migrations/' . substr($version, strrpos($version, '\\') + 1) . '.php';
            if ($bound !== $connection || get_class($migration) !== $version || $file !== $expectedFile || !$migration->isTransactional()) {
                throw new \LogicException('Unapproved migration instance or transaction policy.');
            }
            $steps[$version] = new Step($item->getVersion(), $migration, Direction::UP);
        }
        ksort($steps);
        $expected = array_map(static fn (string $v): string => 'DoctrineMigrations\\Version' . $v, MigrationPlan::VERSIONS);
        if (array_keys($steps) !== $expected) {
            throw new \LogicException('Only the complete approved ascending plan is allowed.');
        }
        return new MigrationPlanList(array_values($steps), Direction::UP);
    }

    public const PUBLIC_OBJECTS_SQL = <<<'SQL'
SELECT c.relname FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'
UNION ALL SELECT p.proname FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public'
UNION ALL SELECT t.typname FROM pg_catalog.pg_type t JOIN pg_catalog.pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname='public'
SQL;
}
