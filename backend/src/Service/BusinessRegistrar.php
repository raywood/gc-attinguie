<?php
declare(strict_types=1);

namespace App\Service;

use App\Dto\BusinessRowInput;
use App\Entity\ActivityType;
use App\Entity\Business;
use App\Entity\BusinessManager;
use App\Entity\Manager;
use Doctrine\ORM\EntityManagerInterface;

/** Enregistre une liste de commerces (et leur gérant) en une seule transaction. */
final class BusinessRegistrar
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<int, ActivityType> indexés par id */
    public function activityTypesById(): array
    {
        $map = [];
        foreach ($this->em->getRepository(ActivityType::class)->findBy([], ['label' => 'ASC']) as $type) {
            $map[$type->getId()] = $type;
        }

        return $map;
    }

    /**
     * Tout ou rien : si une ligne échoue, aucune n'est enregistrée.
     *
     * @param list<BusinessRowInput> $rows validées au préalable
     * @return list<Business>
     */
    public function register(array $rows): array
    {
        return $this->em->wrapInTransaction(function () use ($rows): array {
            $types = $this->activityTypesById();
            $created = [];

            foreach ($rows as $row) {
                $business = (new Business())
                    ->setName((string) $row->name)
                    ->setActivityType($types[$row->activityTypeId])
                    ->setAddress($row->address)
                    ->setDistrict($row->district);
                $this->em->persist($business);

                if ($row->hasManager()) {
                    $manager = (new Manager())
                        ->setLastName((string) $row->managerLastName)
                        ->setFirstName((string) $row->managerFirstName)
                        ->setPhone($row->managerPhone);
                    $this->em->persist($manager);
                    $this->em->persist(new BusinessManager($business, $manager));
                }

                $created[] = $business;
            }

            $this->em->flush();

            return $created;
        });
    }
}
