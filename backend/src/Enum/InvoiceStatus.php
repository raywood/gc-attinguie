<?php
declare(strict_types=1);

namespace App\Enum;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
