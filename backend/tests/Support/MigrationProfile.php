<?php
declare(strict_types=1);

namespace App\Tests\Support;

final class MigrationProfile
{
    public const DATABASE_URL = 'postgresql://gc_test_migrator:migration-test-only@db-test:5432/gc_attinguie_test?serverVersion=16&charset=utf8';

    public static function assertSafe(array $environment): void
    {
        foreach ([
            'GC_TEST_PROFILE' => 'migration', 'APP_ENV' => 'test', 'APP_DEBUG' => '0',
            'DATABASE_URL' => self::DATABASE_URL,
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://', 'TEST_TOKEN' => '',
        ] as $key => $expected) {
            if (($environment[$key] ?? null) !== $expected) {
                throw new \LogicException('Unsafe migration configuration: ' . $key);
            }
        }
    }

    /** Explicit DBAL parameters: no Dotenv, URL parser, suffix or connection fallback. */
    public static function connectionParameters(): array
    {
        return [
            'driver' => 'pdo_pgsql', 'host' => 'db-test', 'port' => 5432,
            'dbname' => 'gc_attinguie_test', 'user' => 'gc_test_migrator',
            'password' => 'migration-test-only', 'serverVersion' => '16', 'charset' => 'utf8',
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 3],
        ];
    }
}
