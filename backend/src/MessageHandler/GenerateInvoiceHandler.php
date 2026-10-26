<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Business;
use App\Message\GenerateInvoiceMessage;
use App\Service\InvoiceGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateInvoiceHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateInvoiceMessage $message): void
    {
        $business = $this->em->find(Business::class, $message->businessId);
        if ($business === null) {
            $this->logger->warning('Commerce introuvable, message ignoré.', ['id' => $message->businessId]);

            return;
        }

        // Si deux workers traitent le même commerce en même temps, la contrainte
        // UNIQUE (business_id, period) lève une exception : Messenger réessaie,
        // et au 2e passage generate() détecte la facture existante et ne fait rien.
        $invoice = $this->generator->generate($business, new \DateTimeImmutable($message->period));

        $this->logger->info($invoice ? 'Facture générée.' : 'Aucune facture à générer.', [
            'business' => $message->businessId,
            'period' => $message->period,
            'number' => $invoice?->getNumber(),
        ]);
    }
}
