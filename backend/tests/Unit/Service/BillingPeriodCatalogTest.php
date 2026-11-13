<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BillingPeriodCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingPeriodCatalogTest extends TestCase
{
    public function testCatalogKeepsStableCodesAndFrenchLabels(): void
    {
        $items = BillingPeriodCatalog::all();

        self::assertSame(['daily', 'weekly', 'monthly', 'yearly'], array_column($items, 'value'));
        self::assertSame(['Journalière', 'Hebdomadaire', 'Mensuelle', 'Annuelle'], array_column($items, 'label'));
    }

    public function testAcceptedPeriodsDoNotEnableUnsupportedBilling(): void
    {
        self::assertSame([
            'daily' => 'daily_record',
            'weekly' => 'catalog_only',
            'monthly' => 'monthly_invoice',
            'yearly' => 'catalog_only',
        ], array_column(BillingPeriodCatalog::all(), 'processing', 'value'));
    }

    #[DataProvider('acceptedPeriods')]
    public function testAcceptsKnownPeriod(string $period): void
    {
        self::assertTrue(BillingPeriodCatalog::accepts($period));
    }

    public static function acceptedPeriods(): iterable
    {
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            yield $period => [$period];
        }
    }

    #[DataProvider('invalidPeriods')]
    public function testRejectsUnknownOrUnnormalizedPeriod(string $period): void
    {
        self::assertFalse(BillingPeriodCatalog::accepts($period));
    }

    public static function invalidPeriods(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['MONTHLY'];
        yield 'leading space' => [' monthly'];
        yield 'trailing space' => ['monthly '];
        yield 'newline' => ["monthly\n"];
        yield 'unknown' => ['biweekly'];
        yield 'path' => ['../daily'];
        yield 'null byte' => ["monthly\0"];
    }

    public function testReturnedCatalogCannotChangeSubsequentResults(): void
    {
        $items = BillingPeriodCatalog::all();
        $items[0]['value'] = 'unsupported';
        $items[0]['processing'] = 'monthly_invoice';

        self::assertSame('daily', BillingPeriodCatalog::all()[0]['value']);
        self::assertSame('daily_record', BillingPeriodCatalog::all()[0]['processing']);
        self::assertFalse(BillingPeriodCatalog::accepts('unsupported'));
    }
}
