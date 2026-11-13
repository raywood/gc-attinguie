<?php
declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\IntegrationLauncher;
use App\Tests\Support\TestEnvironmentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegrationLauncherTest extends TestCase
{
    private static function environment(): array
    {
        return ['APP_ENV' => 'test', 'DATABASE_URL' => TestEnvironmentGuard::DATABASE_URL,
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://', 'TEST_TOKEN' => ''];
    }

    public static function identity(): array
    {
        return ['database_name' => 'gc_attinguie_test', 'user_name' => 'gc_test_runner',
            'session_user_name' => 'gc_test_runner', 'server_version_num' => '160006',
            'marker' => 'gc-attinguie-isolated-test-v1', 'marker_owner' => 'gc_test_admin',
            'schema_owner' => 'gc_test_admin', 'elevated_role' => false,
            'marker_writable' => false, 'schema_writable' => false, 'marker_owner_member' => false];
    }

    public function testOfflineNeverCallsProbe(): void
    {
        $launcher = new IntegrationLauncher(static function (): never { throw new \RuntimeException('Probe called'); });
        self::assertStringContainsString('no connection opened', $launcher->run('check-offline', self::environment()));
    }

    #[DataProvider('invalidDestinations')]
    public function testBadDestinationIsRejectedBeforeProbe(string $key, string $value): void
    {
        $called = false;
        $launcher = new IntegrationLauncher(static function () use (&$called): array { $called = true; return []; });
        $environment = self::environment();
        $environment[$key] = $value;
        try {
            $launcher->run('check-identity', $environment);
            self::fail('Unsafe configuration accepted');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Unsafe integration configuration', $exception->getMessage());
            self::assertFalse($called);
        }
    }

    public static function invalidDestinations(): iterable
    {
        yield ['APP_ENV', 'prod'];
        foreach (['@db-test:' => '@db:', '/gc_attinguie_test?' => '/production?', 'gc_test_runner:' => 'postgres:'] as $from => $to) {
            yield ['DATABASE_URL', str_replace($from, $to, TestEnvironmentGuard::DATABASE_URL)];
        }
        yield ['MESSENGER_TRANSPORT_DSN', 'redis://redis:6379/messages'];
    }

    public function testIdentityUsesSimulationAndChecksEveryInvocation(): void
    {
        $calls = 0;
        $launcher = new IntegrationLauncher(static function () use (&$calls): array { ++$calls; return self::identity(); });
        foreach ([1, 2] as $count) {
            self::assertStringContainsString('identity verified', $launcher->run('check-identity', self::environment()));
            self::assertSame($count, $calls);
        }
    }

    #[DataProvider('sensitiveCommands')]
    public function testSensitiveCommandNeverCallsProbeOrExecutes(string $command): void
    {
        $launcher = new IntegrationLauncher(static function (): never { throw new \RuntimeException('Probe called'); });
        $this->expectException(\LogicException::class);
        $launcher->run($command, self::environment());
    }

    #[DataProvider('sensitiveCommands')]
    public function testSuccessDoesNotAuthorizeSensitiveCommand(string $command): void
    {
        $calls = 0;
        $launcher = new IntegrationLauncher(static function () use (&$calls): array { ++$calls; return self::identity(); });
        $launcher->run('check-offline', self::environment());
        $launcher->run('check-identity', self::environment());
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

    public function testUnknownCommandsAreNotForwarded(): void
    {
        $launcher = new IntegrationLauncher(static function (): never { throw new \RuntimeException('Probe called'); });
        $this->expectException(\LogicException::class);
        $launcher->run('doctrine:migrations:migrate', self::environment());
    }

    public function testProbeFailureCannotProduceSuccess(): void
    {
        $launcher = new IntegrationLauncher(static function (): never { throw new \RuntimeException('Simulated unavailable server'); });
        $this->expectException(\RuntimeException::class);
        $launcher->run('check-identity', self::environment());
    }

    #[DataProvider('unsafeIdentities')]
    public function testUnsafeIdentityCannotPass(string $key, mixed $value): void
    {
        $identity = self::identity();
        $identity[$key] = $value;
        $launcher = new IntegrationLauncher(static fn (): array => $identity);
        $this->expectException(\LogicException::class);
        $launcher->run('check-identity', self::environment());
    }

    public static function unsafeIdentities(): iterable
    {
        yield ['database_name', 'production'];
        yield ['user_name', 'postgres'];
        yield ['session_user_name', 'gc_test_admin'];
        yield ['server_version_num', '150010'];
        yield ['server_version_num', '170001'];
        yield ['server_version_num', null];
        yield ['marker', 'wrong-instance'];
        yield ['marker', null];
        yield ['marker_owner', 'gc_test_runner'];
        yield ['schema_owner', 'gc_test_runner'];
        foreach (['elevated_role', 'marker_writable', 'schema_writable', 'marker_owner_member'] as $key) {
            yield [$key, true];
            yield [$key, null];
        }
    }

    public function testMissingSnapshotIsRejected(): void
    {
        $launcher = new IntegrationLauncher(static fn (): array => []);
        $this->expectException(\LogicException::class);
        $launcher->run('check-identity', self::environment());
    }
}
