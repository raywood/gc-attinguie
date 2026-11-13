<?php
declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Fixtures\MigrationAclFixture as Fixture;
use App\Tests\Support\MigrationAclPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationAclPolicyTest extends TestCase
{
    public function testObservedPublicAclsAndProposedDirectGrantsAreAccepted(): void
    {
        MigrationAclPolicy::assertSafe(Fixture::databases(), Fixture::objects());
        self::assertTrue(true);
    }

    public function testStricterPublicAclIsAcceptedWithoutRegranting(): void
    {
        $rows = Fixture::databases();
        $rows[1]['public_acl'] = [];
        $rows[1]['effective_connect'] = false;
        $rows[1]['effective_temporary'] = false;
        MigrationAclPolicy::assertSafe($rows, Fixture::objects());
        self::assertSame([], $rows[1]['public_acl']);
    }

    #[DataProvider('badDatabases')]
    public function testUnexpectedDatabaseRightsAreRejected(array $rows): void
    {
        $this->expectException(\LogicException::class);
        MigrationAclPolicy::assertSafe($rows, Fixture::objects());
    }

    public static function badDatabases(): iterable
    {
        $base = Fixture::databases();
        foreach (['public_acl', 'direct_acl'] as $key) {
            foreach (['CREATE', 'TEMPORARY', 'CONNECT'] as $privilege) {
                $rows = $base;
                $rows[3][$key] = Fixture::acl([$privilege]);
                if ($key === 'public_acl' && $privilege === 'CONNECT') {
                    $rows[3][$key][0]['grantable'] = true;
                }
                yield $key . ' unexpected ' . $privilege => [$rows];
            }
            $rows = $base;
            $rows[0][$key][0]['grantable'] = true;
            yield $key . ' grant option' => [$rows];
            $rows = $base;
            $rows[0][$key][0]['grantor'] = 'unexpected_role';
            yield $key . ' grantor' => [$rows];
        }
        foreach (['owner' => 'gc_test_migrator', 'allow_connections' => false,
            'effective_create' => true, 'effective_connect' => null, 'effective_temporary' => false,
            'public_acl' => null, 'direct_acl' => []] as $key => $value) {
            $rows = $base;
            $rows[0][$key] = $value;
            yield $key => [$rows];
        }
        $rows = $base;
        $rows[0]['public_acl'] = Fixture::acl(['CONNECT', 'TEMPORARY']);
        yield 'unexpected public CONNECT on target' => [$rows];
        $rows = $base;
        $rows[2]['allow_connections'] = true;
        yield 'template0 opened' => [$rows];
        $rows = $base;
        $rows[] = [...$base[0], 'name' => 'production'];
        yield 'new accessible database' => [$rows];
        $rows = $base;
        $rows[] = [...$base[0], 'name' => 'other_test', 'allow_connections' => false];
        yield 'new disabled database' => [$rows];
        $rows = $base;
        $rows[] = $base[0];
        yield 'duplicate database' => [$rows];
        yield 'missing database' => [array_slice($base, 1)];
        $rows = $base;
        $rows[0]['public_acl'][] = $rows[0]['public_acl'][0];
        yield 'duplicate privilege' => [$rows];
    }

    #[DataProvider('badObjects')]
    public function testUnexpectedObjectAclsAndOwnersAreRejected(array $rows): void
    {
        $this->expectException(\LogicException::class);
        MigrationAclPolicy::assertSafe(Fixture::databases(), $rows);
    }

    public static function badObjects(): iterable
    {
        $base = Fixture::objects();
        foreach ([0, 1, 2, 3] as $index) {
            $rows = $base;
            $rows[$index]['owner'] = 'gc_test_migrator';
            yield 'changed owner ' . $index => [$rows];
            foreach (['public_acl', 'direct_acl'] as $key) {
                $rows = $base;
                $rows[$index][$key] = Fixture::acl(['CREATE'], $base[$index]['owner']);
                if ($index === 0 && $key === 'direct_acl') {
                    $rows[$index][$key] = Fixture::acl(['CREATE', 'USAGE'], $base[$index]['owner']);
                    $rows[$index][$key][0]['grantable'] = true;
                }
                yield 'extra object right ' . $index . $key => [$rows];
            }
        }
        $rows = $base;
        $rows[2]['direct_acl'] = Fixture::acl(['SELECT', 'UPDATE']);
        yield 'marker update' => [$rows];
        $rows = $base;
        $rows[2]['public_acl'] = Fixture::acl(['SELECT']);
        yield 'marker exposed to PUBLIC' => [$rows];
        $rows = $base;
        $rows[2]['direct_acl'][0]['grantable'] = true;
        yield 'marker SELECT delegation' => [$rows];
        $rows = $base;
        $rows[1]['direct_acl'] = Fixture::acl(['USAGE', 'CREATE']);
        yield 'marker schema CREATE' => [$rows];
        yield 'missing resource' => [array_slice($base, 1)];
        $rows = $base;
        $rows[] = $base[0];
        yield 'duplicate resource' => [$rows];
        $rows = $base;
        $rows[0]['resource'] = 'schema:other';
        yield 'unknown resource' => [$rows];
    }
}
