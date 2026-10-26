<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Enum\InvoiceStatus;
use App\Exception\InvoiceNotIssuableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fait passer une facture de « brouillon » à « émise » : elle n'est alors plus modifiable
 * (Invoice::addLine() refuse toute modification hors brouillon) et reçoit une échéance.
 */
final class InvoiceIssuer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.invoice_due_days%')]
        private readonly int $dueDays,
    ) {
    }

    /** @throws InvoiceNotIssuableException */
    public function issue(Invoice $invoice, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        if ($invoice->getStatus() !== InvoiceStatus::Draft) {
            throw new InvoiceNotIssuableException(sprintf(
                'La facture %s ne peut pas être émise : statut « %s ».',
                $invoice->getNumber(),
                $invoice->getStatus()->value,
            ));
        }

        if ($invoice->getLines()->count() === 0 || bccomp($invoice->getTotal(), '0', 2) <= 0) {
            throw new InvoiceNotIssuableException(sprintf(
                'La facture %s est vide ou son total est nul.',
                $invoice->getNumber(),
            ));
        }

        $invoice->setStatus(InvoiceStatus::Issued);
        $invoice->setIssuedAt($now);
        $invoice->setDueAt($now->setTime(0, 0)->modify(sprintf('+%d days', $this->dueDays)));

        $this->em->flush();
    }
}
