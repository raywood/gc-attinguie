<?php
declare(strict_types=1);

namespace App\Tests\Fixtures;

/** Simulated future migrator: observed PUBLIC ACLs plus the proposed direct grants. */
final class MigrationAclFixture
{
    public static function acl(array $privileges, string $grantor = 'gc_test_admin'): array
    {
        return array_map(static fn (string $p): array => ['privilege' => $p, 'grantable' => false, 'grantor' => $grantor], $privileges);
    }

    public static function databases(): array
    {
        $rows = [];
        foreach ([
            'gc_attinguie_test' => ['TEMPORARY'], 'postgres' => ['CONNECT', 'TEMPORARY'],
            'template0' => ['CONNECT'], 'template1' => ['CONNECT'],
        ] as $name => $public) {
            $rows[] = ['name' => $name, 'owner' => 'gc_test_admin', 'allow_connections' => $name !== 'template0',
                'public_acl' => self::acl($public),
                'direct_acl' => self::acl($name === 'gc_attinguie_test' ? ['CONNECT'] : []),
                'effective_connect' => true, 'effective_temporary' => in_array('TEMPORARY', $public, true),
                'effective_create' => false];
        }
        return $rows;
    }

    public static function objects(): array
    {
        $rows = [];
        foreach ([
            'schema:public' => ['pg_database_owner', ['USAGE'], ['CREATE', 'USAGE']],
            'schema:isolation_meta' => ['gc_test_admin', [], ['USAGE']],
            'table:isolation_meta.identity' => ['gc_test_admin', [], ['SELECT']],
            'language:plpgsql' => ['gc_test_admin', ['USAGE'], ['USAGE']],
        ] as $name => [$owner, $public, $direct]) {
            $rows[] = ['resource' => $name, 'owner' => $owner,
                'public_acl' => self::acl($public, $owner), 'direct_acl' => self::acl($direct, $owner)];
        }
        return $rows;
    }
}
