<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Business;
use App\Entity\BusinessBillingProfile;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final class BillingProfileResolver
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function at(Business $business, \DateTimeImmutable $date): ?BusinessBillingProfile
    {
        return $this->em->getRepository(BusinessBillingProfile::class)->createQueryBuilder('p')
            ->where('p.business = :business AND p.effectiveFrom <= :date')
            ->setParameter('business', $business)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->orderBy('p.effectiveFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function frequencyAt(Business $business, \DateTimeImmutable $date): string
    {
        // Repli historique en mensuel pour un commerce créé avant ce lot.
        return $this->at($business, $date)?->getFrequency() ?? 'monthly';
    }
}
