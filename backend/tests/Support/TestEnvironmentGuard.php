<?php
declare(strict_types=1);

namespace App\Tests\Support;

/** Pure configuration check: never resolves DNS or opens a connection. */
final class TestEnvironmentGuard
{
    public const DATABASE_URL = 'postgresql://gc_test_runner:test-only@db-test:5432/gc_attinguie_test?serverVersion=16&charset=utf8';

    /** @param array<string, mixed> $environment */
    public static function assertSafe(array $environment): void
    {
        // Exact allow-list also rejects alternate ports, encoded hosts, socket
        // parameters and query overrides that a partial URL check could miss.
        foreach ([
            'APP_ENV' => 'test',
            'DATABASE_URL' => self::DATABASE_URL,
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
            'TEST_TOKEN' => '',
        ] as $name => $expected) {
            if (($environment[$name] ?? null) !== $expected) {
                // Never include supplied values: they may contain real secrets.
                throw new \LogicException('Unsafe integration configuration: ' . $name);
            }
        }
    }
}
