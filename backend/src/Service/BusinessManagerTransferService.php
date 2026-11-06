<?php
declare(strict_types=1);

namespace App\Service;

use App\Dto\BusinessRowInput;
use App\Entity\Business;
use App\Entity\BusinessManager;
use App\Entity\BusinessManagerTransferAudit;
use App\Entity\MerchantBusinessAccess;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Remplacement du gérant principal avec révocation atomique et traçabilité. */
final class BusinessManagerTransferService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerAccountProvisioner $provisioner,
    ) {}

    /** @return array{newManager:array{name:string,email:string}, invitation:?array} */
    public function transfer(Business $business, User $actor, array $values): array
    {
        return $this->em->wrapInTransaction(function () use ($business, $actor, $values): array {
            $connection = $this->em->getConnection();
            // Verrouillage du commerce : deux administrateurs ne peuvent transférer simultanément.
            $locked = $connection->fetchOne('SELECT id FROM businesses WHERE id = :id FOR UPDATE', ['id' => $business->getId()]);
            if ($locked === false) {
                throw new \DomainException('Commerce introuvable.');
            }

            $activeLink = $this->em->getRepository(BusinessManager::class)->findOneBy([
                'business' => $business,
                'isPrimary' => true,
                'endedAt' => null,
            ]);
            $previousManager = $activeLink?->getManager();
            $previousUser = $previousManager?->getUser();
            $newEmail = mb_strtolower(trim($values['managerEmail']));

            if ($previousUser !== null && mb_strtolower($previousUser->getEmail()) === $newEmail) {
                throw new \DomainException('Ce compte est déjà le gérant principal. Utilisez la modification des coordonnées.');
            }
            if ($previousUser === null && $previousManager !== null && $previousManager->getEmail() !== null
                && mb_strtolower($previousManager->getEmail()) === $newEmail) {
                throw new \DomainException('Le gérant actuel possède déjà cet e-mail. Vérifiez son rattachement avant transfert.');
            }

            // Les comptes municipaux ne sont pas des comptes gérants exclusivement limités à leurs commerces.
            $existingId = $connection->fetchOne('SELECT id FROM users WHERE LOWER(email) = :email FOR UPDATE', ['email' => $newEmail]);
            if ($existingId !== false) {
                $existingUser = $this->em->find(User::class, (int) $existingId);
                if ($existingUser !== null && array_intersect(
                    ['ROLE_ADMIN', 'ROLE_AGENT', 'ROLE_REGISSEUR', 'ROLE_COMPTABLE'],
                    $existingUser->getRoles(),
                )) {
                    throw new \DomainException('Ce compte détient déjà des droits municipaux. Vérifiez son identité dans l’administration.');
                }
            }

            // Clore l'ancien lien d'abord pour libérer l'index unique partiel.
            if ($activeLink !== null) {
                $activeLink->setEndedAt(new \DateTimeImmutable('today', new \DateTimeZone('Africa/Abidjan')));
                $this->em->flush();
            }
            // Révocation *uniquement* pour ce commerce (autres commerces préservés).
            if ($previousUser !== null) {
                $oldAccess = $this->em->getRepository(MerchantBusinessAccess::class)->findOneBy([
                    'user' => $previousUser,
                    'business' => $business,
                ]);
                if ($oldAccess !== null && $oldAccess->getRevokedAt() === null) {
                    $oldAccess->revoke();
                }
            }

            // Réutilisation contrôlée : identité (nom/prénom) vérifiée par le provisioner.
            // Un nouveau compte reste inactif en attendant son activation.
            $row = new BusinessRowInput(
                managerLastName: $values['managerLastName'],
                managerFirstName: $values['managerFirstName'],
                managerPhone: $values['managerPhone'] !== '' ? $values['managerPhone'] : null,
                managerEmail: $newEmail,
            );
            $invitation = $this->provisioner->attach($business, $row, $actor);
            $this->em->flush();

            $currentLink = $this->em->getRepository(BusinessManager::class)->findOneBy([
                'business' => $business,
                'isPrimary' => true,
                'endedAt' => null,
            ]);
            if ($currentLink === null || $currentLink->getManager()->getUser() === null) {
                throw new \LogicException('Le rattachement du nouveau gérant n’a pas pu être confirmé.');
            }
            $newManager = $currentLink->getManager();
            $newUser = $newManager->getUser();
            $audit = new BusinessManagerTransferAudit(
                $business, $previousManager, $newManager, $previousUser, $newUser,
                $actor, $values['reason'],
            );
            $this->em->persist($audit);
            $this->em->flush();

            return [
                'newManager' => ['name' => $newManager->getFullName(), 'email' => $newEmail],
                'invitation' => $invitation,
            ];
        });
    }
}
