<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Source de vérité des périodicités acceptées par le catalogue tarifaire.
 * Une périodicité acceptée ne signifie PAS qu'un moteur de facturation existe.
 * Les codes restent stables pour les données historiques et les CHECK PostgreSQL.
 */
final class BillingPeriodCatalog
{
    private const PERIODS = [
        'daily' => [
            'label' => 'Journalière',
            'processing' => 'daily_record',
        ],
        'weekly' => [
            'label' => 'Hebdomadaire',
            'processing' => 'catalog_only',
        ],
        'monthly' => [
            'label' => 'Mensuelle',
            'processing' => 'monthly_invoice',
        ],
        'yearly' => [
            'label' => 'Annuelle',
            'processing' => 'catalog_only',
        ],
    ];

    public static function accepts(string $period): bool
    {
        return array_key_exists($period, self::PERIODS);
    }

    /** @return list<array{value: string, label: string, processing: string}> */
    public static function all(): array
    {
        $items = [];
        foreach (self::PERIODS as $value => $details) {
            $items[] = ['value' => $value, ...$details];
        }
        return $items;
    }
}
