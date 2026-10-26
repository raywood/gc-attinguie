<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentMethod;
use App\Exception\PaymentRejectedException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre un paiement (total ou partiel) sur une facture émise.
 * La facture passe à « payée » dès que le solde atteint zéro.
 */
final class PaymentRecorder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param string $amount Montant décimal positif, ex. "5000" ou "5000.50"
     *
     * @throws PaymentRejectedException
     */
    public function record(
        Invoice $invoice,
        string $amount,
        PaymentMethod $method,
        ?string $reference = null,
        ?\DateTimeImmutable $receivedAt = null,
    ): Payment {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new PaymentRejectedException('Montant invalide : utilisez un nombre positif (ex. 5000 ou 5000.50).');
        }
        $amount = bcadd($amount, '0', 2);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new PaymentRejectedException('Le montant doit être supérieur à zéro.');
        }

        return $this->em->wrapInTransaction(function () use ($invoice, $amount, $method, $reference, $receivedAt): Payment {
            // Verrou + rechargement : deux paiements simultanés ne peuvent pas dépasser le total.
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);

            if ($invoice->getStatus() !== InvoiceStatus::Issued) {
                throw new PaymentRejectedException(sprintf(
                    'La facture %s ne peut pas recevoir de paiement : statut « %s ».',
                    $invoice->getNumber(),
                    $invoice->getStatus()->value,
                ));
            }

            $balance = $this->balance($invoice);
            if (bccomp($amount, $balance, 2) > 0) {
                throw new PaymentRejectedException(sprintf(
                    'Le montant (%s) dépasse le solde restant dû (%s).',
                    $amount,
                    $balance,
                ));
            }

            $payment = new Payment($invoice, $amount, $method);
            $payment->setReference($reference);
            if ($receivedAt !== null) {
                $payment->setReceivedAt($receivedAt);
            }

            $invoice->getPayments()->add($payment);
            $this->em->persist($payment);

            if (bccomp($amount, $balance, 2) === 0) {
                $invoice->setStatus(InvoiceStatus::Paid);
            }

            $this->em->flush();

            return $payment;
        });
    }

    public function paidAmount(Invoice $invoice): string
    {
        $sum = '0.00';
        foreach ($invoice->getPayments() as $payment) {
            $sum = bcadd($sum, $payment->getAmount(), 2);
        }

        return $sum;
    }

    public function balance(Invoice $invoice): string
    {
        return bcsub($invoice->getTotal(), $this->paidAmount($invoice), 2);
    }
}
