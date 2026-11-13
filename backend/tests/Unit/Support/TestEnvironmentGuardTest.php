<?php
declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\TestEnvironmentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestEnvironmentGuardTest extends TestCase
{
    private static function safeEnvironment(): array
    {
        return [
            'APP_ENV' => 'test',
            'DATABASE_URL' => TestEnvironmentGuard::DATABASE_URL,
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
            'TEST_TOKEN' => '',
        ];
    }

    public function testAcceptsOnlyDedicatedConfiguration(): void
    {
        $environment = self::safeEnvironment();
        TestEnvironmentGuard::assertSafe($environment);
        self::assertSame(self::safeEnvironment(), $environment);
    }

    #[DataProvider('unsafeValues')]
    public function testRejectsUnsafeConfigurationWithoutConnection(string $name, mixed $value): void
    {
        $environment = self::safeEnvironment();
        $environment[$name] = $value;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsafe integration configuration: ' . $name);
        TestEnvironmentGuard::assertSafe($environment);
    }

    public static function unsafeValues(): iterable
    {
        yield 'production environment' => ['APP_ENV', 'prod'];
        yield 'development environment' => ['APP_ENV', 'dev'];
        yield 'missing environment' => ['APP_ENV', null];
        yield 'missing URL' => ['DATABASE_URL', null];
        yield 'invalid URL type' => ['DATABASE_URL', []];
        foreach ([
            'development host' => ['@db-test:', '@db:'],
            'production host' => ['@db-test:', '@production.example:'],
            'loopback host' => ['@db-test:', '@127.0.0.1:'],
            'production database' => ['/gc_attinguie_test?', '/gc_attinguie_prod?'],
            'double suffix' => ['/gc_attinguie_test?', '/gc_attinguie_test_test?'],
            'admin user' => ['gc_test_runner:', 'gc_test_admin:'],
            'wrong port' => [':5432/', ':5433/'],
            'wrong password' => [':test-only@', ':real-secret@'],
        ] as $label => [$from, $to]) {
            yield $label => ['DATABASE_URL', str_replace($from, $to, TestEnvironmentGuard::DATABASE_URL)];
        }
        yield 'host override' => ['DATABASE_URL', TestEnvironmentGuard::DATABASE_URL . '&host=production.example'];
        yield 'socket override' => ['DATABASE_URL', TestEnvironmentGuard::DATABASE_URL . '&host=/var/run/postgresql'];
        yield 'real redis' => ['MESSENGER_TRANSPORT_DSN', 'redis://redis:6379/messages'];
        yield 'missing transport' => ['MESSENGER_TRANSPORT_DSN', null];
        yield 'parallel token' => ['TEST_TOKEN', '1'];
        yield 'missing token' => ['TEST_TOKEN', null];
    }

    public function testMissingConfigurationIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        TestEnvironmentGuard::assertSafe([]);
    }

    public function testErrorDoesNotExposeCredentials(): void
    {
        $environment = self::safeEnvironment();
        $environment['DATABASE_URL'] = 'postgresql://admin:private-password@production.example/real';
        try {
            TestEnvironmentGuard::assertSafe($environment);
            self::fail('Unsafe destination was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('Unsafe integration configuration: DATABASE_URL', $exception->getMessage());
        }
    }
}
