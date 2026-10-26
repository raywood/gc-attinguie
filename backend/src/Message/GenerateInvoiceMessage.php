<?php
declare(strict_types=1);

namespace App\Message;

final readonly class GenerateInvoiceMessage
{
    /** @param string $period Premier jour du mois, format Y-m-d */
    public function __construct(
        public int $businessId,
        public string $period,
    ) {
    }
}
