<?php
declare(strict_types=1);

namespace App\Message;

final readonly class IssueInvoiceMessage
{
    public function __construct(public int $invoiceId)
    {
    }
}
