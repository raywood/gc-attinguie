<?php
declare(strict_types=1);

namespace App\Tests\Support;

final class IntegrationLauncher
{
    private readonly \Closure $readIdentity;

    /** @param callable(array<string, mixed>): array<string, mixed> $readIdentity */
    public function __construct(callable $readIdentity)
    {
        $this->readIdentity = \Closure::fromCallable($readIdentity);
    }

    /** @param array<string, mixed> $environment */
    public function run(string $command, array $environment): string
    {
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
