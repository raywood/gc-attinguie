<?php
declare(strict_types=1);

namespace App\Service;

use App\Dto\BusinessRowInput;
use App\Entity\ActivityType;
use App\Entity\Business;
use App\Entity\BusinessManager;
use App\Entity\Manager;
use App\Enum\BusinessStatus;
use Doctrine\ORM\EntityManagerInterface;

/** Modifie un commerce en preservant l'historique des gerants. */
final class BusinessUpdater
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function update(Business $business, BusinessRowInput $input, BusinessStatus $status): void
    {
        $this->em->wrapInTransaction(function () use ($business, $input, $status): void {
            $type = $this->em->find(ActivityType::class, $input->activityTypeId);
            if ($type === null) {
                throw new \InvalidArgumentException('Type d’activité inconnu.');
            }

            $business
                ->setName((string) $input->name)
                ->setActivityType($type)
                ->setAddress($input->address)
                ->setDistrict($input->district)
                ->setStatus($status);

            $link = $this->em->getRepository(BusinessManager::class)->findOneBy([
                'business' => $business,
                'isPrimary' => true,
                'endedAt' => null,
            ]);
            $current = $link?->getManager();
            $wantsManager = $input->hasManager();
            $managerChanged = $current !== null && (!$wantsManager
                || $current->getLastName() !== $input->managerLastName
                || $current->getFirstName() !== $input->managerFirstName
                || $current->getPhone() !== $input->managerPhone);

            if ($link !== null && $managerChanged) {
                // Indispensable avant la creation du nouveau lien : index unique partiel.
                $link->setEndedAt(new \DateTimeImmutable('today'));
                $this->em->flush();
            }

            if ($wantsManager && ($link === null || $managerChanged)) {
                // Un nouveau gerant conserve l'historique et evite de modifier un
                // Manager eventuellement partage entre plusieurs commerces.
                $manager = (new Manager())
                    ->setLastName((string) $input->managerLastName)
                    ->setFirstName((string) $input->managerFirstName)
                    ->setPhone($input->managerPhone);
                $this->em->persist($manager);
                $this->em->persist(new BusinessManager($business, $manager));
            }

            $this->em->flush();
        });
    }
}
