<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Service/BillingPeriodCatalog.php';

use App\Service\BillingPeriodCatalog;

$items = BillingPeriodCatalog::all();
$codes = array_column($items, 'value');
$expected = ['daily', 'weekly', 'monthly', 'yearly'];
if ($codes !== $expected) {
    throw new RuntimeException('Ordre ou codes de périodicité inattendus.');
}
foreach ($expected as $code) {
    if (!BillingPeriodCatalog::accepts($code)) {
        throw new RuntimeException('Périodicité légitime refusée : ' . $code);
    }
}
foreach (['', 'MONTHLY', 'biweekly', 'monthly ', '../daily'] as $invalid) {
    if (BillingPeriodCatalog::accepts($invalid)) {
        throw new RuntimeException('Périodicité inconnue acceptée : ' . $invalid);
    }
}
foreach ($items as $item) {
    if (!isset($item['label'], $item['processing']) || !is_string($item['label'])) {
        throw new RuntimeException('Métadonnées de période invalides.');
    }
}
$mode = array_column($items, 'processing', 'value');
if ($mode !== [
    'daily' => 'daily_record',
    'weekly' => 'catalog_only',
    'monthly' => 'monthly_invoice',
    'yearly' => 'catalog_only',
]) {
    throw new RuntimeException('Capacité métier modifiée : facturation potentiellement dangereuse.');
}
echo "OK : catalogue des quatre périodicités et capacités métier inchangées.\n";
