<?php
declare(strict_types=1);

namespace App\Tests\Support;

/** Offline source inventory, not Doctrine migrate --dry-run. Never calls up(). */
final class MigrationPlan
{
    public const VERSIONS = [
        '20261026120000', '20261026145526', '20261026150204', '20261027130000',
        '20261027220000', '20261029190000', '20261029210000', '20261029234500',
        '20261102160000', '20261102193000', '20261105223000', '20261106090000',
        '20261106113000', '20261106170000', '20261108220000', '20261112221500', '20261112234500',
    ];

    public static function manifest(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/migrations.manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function inspect(?array $manifest = null): array
    {
        $manifest ??= self::manifest();
        if (array_column($manifest['migrations'] ?? [], 'version') !== self::VERSIONS) {
            throw new \LogicException('Unexpected migration versions or order.');
        }
        $directory = dirname(__DIR__, 2) . '/migrations';
        $expectedFiles = array_map(static fn (string $v): string => $directory . '/Version' . $v . '.php', self::VERSIONS);
        $actualFiles = glob($directory . '/*.php');
        sort($actualFiles);
        if ($actualFiles !== $expectedFiles) {
            throw new \LogicException('Unexpected migration files.');
        }
        foreach ($manifest['migrations'] as $item) {
            $path = $directory . '/Version' . $item['version'] . '.php';
            if (is_link($path) || !hash_equals($item['sha256'] ?? '', hash_file('sha256', $path))) {
                throw new \LogicException('Migration source changed: review required.');
            }
            $expectedDrops = match ($item['version']) {
                '20261026145526', '20261026150204' => ['uniq_business_primary_manager'],
                '20261112221500' => ['idx_gc_billing_business_date'],
                default => [],
            };
            if (($item['dropped_indexes'] ?? null) !== $expectedDrops) {
                throw new \LogicException('Unexpected destructive index operations.');
            }
        }
        return $manifest;
    }

    public static function assertInitialState(array $publicObjects, array $executedVersions): void
    {
        if ($publicObjects !== [] || $executedVersions !== []) {
            throw new \LogicException('Initial application schema is not empty; no automatic repair.');
        }
    }

    public static function assertCompletedState(array $tables, array $executedVersions): void
    {
        $manifest = self::inspect();
        $expectedTables = [...$manifest['tables'], 'doctrine_migration_versions'];
        $expectedVersions = array_map(static fn (string $v): string => 'DoctrineMigrations\\Version' . $v, self::VERSIONS);
        sort($expectedTables);
        sort($tables);
        sort($expectedVersions);
        sort($executedVersions);
        if ($tables !== $expectedTables || $executedVersions !== $expectedVersions) {
            throw new \LogicException('Incomplete or unexpected migration result.');
        }
    }
}
