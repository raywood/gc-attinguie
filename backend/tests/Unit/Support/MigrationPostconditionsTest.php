<?php
declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Fixtures\MigrationExecutionFixture;
use App\Tests\Support\MigrationPostconditions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationPostconditionsTest extends TestCase
{
    public function testCompleteSyntheticCatalogue(): void
    {
        MigrationPostconditions::assertSnapshot(MigrationExecutionFixture::snapshot());
        self::assertCount(63, MigrationPostconditions::specification()['constraints']);
        self::assertCount(60, MigrationPostconditions::specification()['indexes']);
    }

    #[DataProvider('mutations')]
    public function testUnexpectedCatalogueRefused(string $mutation): void
    {
        $snapshot = MigrationExecutionFixture::snapshot();
        switch ($mutation) {
            case 'missing table': array_shift($snapshot['relations']); break;
            case 'unknown object': $snapshot['relations'][] = ['name' => 'unknown', 'kind' => 'v', 'owner' => 'gc_test_migrator', 'persistence' => 'p', 'unsafe' => false]; break;
            case 'owner': $snapshot['relations'][0]['owner'] = 'gc_test_admin'; break;
            case 'public grant': $snapshot['relations'][0]['unsafe'] = true; break;
            case 'version': array_pop($snapshot['versions']); break;
            case 'unknown version': $snapshot['versions'][] = 'DoctrineMigrations\\Version20990101000000'; break;
            case 'missing constraint': array_pop($snapshot['constraints']); break;
            case 'unvalidated': $snapshot['constraints'][0]['validated'] = false; break;
            case 'deferred': $snapshot['constraints'][0]['deferrable'] = true; break;
            case 'foreign key': foreach ($snapshot['constraints'] as &$row) { if ($row['type'] === 'f') { $row['delete_action'] = 'c'; break; } } break;
            case 'check': foreach ($snapshot['constraints'] as &$row) { if ($row['type'] === 'c') { $row['expression'] = 'true'; break; } } break;
            case 'missing index': array_pop($snapshot['indexes']); break;
            case 'invalid index': $snapshot['indexes'][0]['valid'] = false; break;
            case 'nonunique': $snapshot['indexes'][0]['unique'] = false; break;
            case 'index columns': $snapshot['indexes'][0]['keys'] = 'wrong'; break;
            case 'partial index': $snapshot['indexes'][0]['predicate'] = 'false'; break;
            case 'function owner': $snapshot['functions'][0]['owner'] = 'gc_test_admin'; break;
            case 'definer': $snapshot['functions'][0]['unsafe'] = true; break;
            case 'function body': $snapshot['functions'][0]['body'] = 'BEGIN RETURN NEW; END;'; break;
            case 'disabled trigger': $snapshot['triggers'][0]['enabled'] = 'D'; break;
            case 'different trigger': $snapshot['triggers'][0]['type'] = '19'; break;
            case 'trigger schema': $snapshot['triggers'][0]['function_schema'] = 'attacker'; break;
        }
        $this->expectException(\LogicException::class);
        MigrationPostconditions::assertSnapshot($snapshot);
    }

    public static function mutations(): iterable
    {
        foreach (['missing table','unknown object','owner','public grant','version','unknown version',
            'missing constraint','unvalidated','deferred','foreign key','check','missing index','invalid index',
            'nonunique','index columns','partial index','function owner','definer','function body',
            'disabled trigger','different trigger','trigger schema'] as $mutation) { yield $mutation => [$mutation]; }
    }
}
