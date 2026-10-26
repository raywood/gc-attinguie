<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Business;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\User;
use App\Enum\BusinessStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère la facture mensuelle (en brouillon, donc éditable) d'un commerce.
 * Idempotent : retourne null si rien à créer (déjà facturé, inactif, tarif nul).
 */
final class InvoiceGenerator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function generate(Business $business, \DateTimeImmutable $period, ?User $by = null): ?Invoice
    {
        $period = $period->modify('first day of this month')->setTime(0, 0);

        if ($business->getStatus() !== BusinessStatus::Active) {
            return null;
        }

        $exists = $this->em->getRepository(Invoice::class)
            ->findOneBy(['business' => $business, 'period' => $period]);
        if ($exists !== null) {
            return null;
        }

        $type = $business->getActivityType();
        $fee = $type->getMonthlyFee();
        if (bccomp($fee, '0', 2) <= 0) {
            return null;
        }

        $invoice = new Invoice($business, $period);
        $invoice->setNumber(sprintf('FAC-%s-%06d', $period->format('Ym'), $business->getId()));
        $invoice->setCurrency($type->getCurrency());
        $invoice->setCreatedBy($by);
        $invoice->addLine(new InvoiceLine(
            $invoice,
            sprintf('Redevance mensuelle %s — %s', $type->getLabel(), $period->format('m/Y')),
            $fee,
        ));

        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }
}
