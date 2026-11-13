<?php
declare(strict_types=1);

namespace App\Tests\Support;

final class IntegrationLauncher
{
    private readonly \Closure $readIdentity;
    private readonly ?\Closure $readMigrationIdentity;

    /** @param callable(array<string, mixed>): array<string, mixed> $readIdentity */
    public function __construct(callable $readIdentity, ?callable $readMigrationIdentity = null)
    {
        $this->readIdentity = \Closure::fromCallable($readIdentity);
        $this->readMigrationIdentity = $readMigrationIdentity === null ? null : \Closure::fromCallable($readMigrationIdentity);
    }

    /** @param array<string, mixed> $environment */
    public function run(string $command, array $environment): string
    {
        if (in_array($command, ['check-migration-offline', 'check-migration-identity'], true)) {
            MigrationProfile::assertSafe($environment);
            MigrationPlan::inspect();
            if ($command === 'check-migration-offline') {
                return 'OK: migration profile and 17 pinned sources verified offline; writes disabled.';
            }
            if ($this->readMigrationIdentity === null) {
                throw new \LogicException('Migration identity probe unavailable.');
            }
            return ($this->readMigrationIdentity)($environment);
        }
        if (in_array($command, ['prepare', 'reset'], true) && ($environment['GC_TEST_PROFILE'] ?? null) === 'migration') {
            MigrationProfile::assertSafe($environment);
            // SecureMigrationExecutor exists, but enabling writes requires a separate mission.
            // No flag, environment variable or successful identity check can enable it here.
            if ($command === 'prepare') {
                SecureMigrationExecutor::prepare($environment);
            }
            throw new \LogicException('Sensitive operation disabled: guarded execution is not implemented.');
        }
        TestEnvironmentGuard::assertSafe($environment);

        if ($command === 'check-offline') {
            return 'OK: offline configuration; no connection opened.';
        }
        // Deliberately no implementation or authorization token for writes.
        // A previous successful check, even in this process, grants no permission.
        if (in_array($command, ['prepare', 'reset'], true)) {
            throw new \LogicException('Sensitive operation disabled: guarded execution is not implemented.');
        }
        if ($command !== 'check-identity') {
            throw new \LogicException('Unsupported integration command.');
        }

        DatabaseIdentityGuard::assertSafe(($this->readIdentity)($environment));

        return 'OK: PostgreSQL identity verified; no sensitive operation authorized.';
    }
}
