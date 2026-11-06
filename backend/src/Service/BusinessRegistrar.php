<?php
declare(strict_types=1);

namespace App\Service;

use App\Dto\BusinessRowInput;
use App\Entity\ActivityType;
use App\Entity\Business;
use App\Entity\BusinessManager;
use App\Entity\User;
use App\Entity\BusinessBillingProfile;
use Doctrine\ORM\EntityManagerInterface;

/** Enregistre une liste de commerces (et leur gérant) en une seule transaction. */
final class BusinessRegistrar
{
    // gc-manager-link-v1
    private array $invitations = [];
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerAccountProvisioner $accounts,
    ) {}
    /** Les jetons d'activation ne sont renvoyés qu'une fois à l'administrateur. */
    public function invitations(): array { return $this->invitations; }

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
    public function register(array $rows, ?User $actor = null): array
    {
        $this->invitations = [];
        $invites = [];
        $created = $this->em->wrapInTransaction(function () use ($rows, $actor, &$invites): array {
            $types = $this->activityTypesById();
            $created = [];

            foreach ($rows as $row) {
                $business = (new Business())
                    ->setName((string) $row->name)
                    ->setActivityType($types[$row->activityTypeId])
                    ->setAddress($row->address)
                    ->setDistrict($row->district);
                $this->em->persist($business);

                // Initialiser le profil de facturation des nouveaux commerces.
                $this->em->persist(new BusinessBillingProfile(
                    $business, 'monthly',
                    new \DateTimeImmutable('first day of this month', new \DateTimeZone('Africa/Abidjan')),
                    'Profil mensuel initial', $actor,
                ));
                // Transaction unique : commerce, compte, gérant, association et accès.
                $invite = $this->accounts->attach($business, $row, $actor);
                if ($invite !== null) $invites[] = $invite;

                $created[] = $business;
            }

            $this->em->flush();

            return $created;
        });
        $this->invitations = $invites;
        return $created;
    }
}
