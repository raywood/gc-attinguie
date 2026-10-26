<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Invoice;
use App\Enum\InvoiceStatus;
use App\Exception\InvoiceNotIssuableException;
use App\Message\IssueInvoiceMessage;
use App\Service\InvoiceIssuer;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IssueInvoiceHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceIssuer $issuer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(IssueInvoiceMessage $message): void
    {
        // Verrou pessimiste : deux workers ne peuvent pas émettre la même facture en parallèle.
        $this->em->wrapInTransaction(function () use ($message): void {
            $invoice = $this->em->find(Invoice::class, $message->invoiceId, LockMode::PESSIMISTIC_WRITE);

            if ($invoice === null) {
                $this->logger->warning('Facture introuvable, message ignoré.', ['id' => $message->invoiceId]);

                return;
            }

            if ($invoice->getStatus() !== InvoiceStatus::Draft) {
                $this->logger->info('Facture déjà traitée, ignorée.', ['number' => $invoice->getNumber()]);

                return;
            }

            try {
                $this->issuer->issue($invoice);
                $this->logger->info('Facture émise.', ['number' => $invoice->getNumber()]);
            } catch (InvoiceNotIssuableException $e) {
                // Inutile de réessayer : on journalise et on laisse la facture en brouillon.
                $this->logger->warning($e->getMessage());
            }
        });
    }
}
