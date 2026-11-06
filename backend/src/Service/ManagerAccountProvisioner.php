<?php
declare(strict_types=1);

namespace App\Service;

use App\Dto\BusinessRowInput;
use App\Entity\Business;
use App\Entity\BusinessManager;
use App\Entity\Manager;
use App\Entity\MerchantBusinessAccess;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Un compte par adresse e-mail ; réutilisation uniquement avec identité concordante.
 * Utiliser dans la transaction du BusinessRegistrar.
 */
final class ManagerAccountProvisioner
{
    /** Évite de dupliquer le gérant dans une saisie multiple avec le même e-mail. */
    private array $managerByUserId = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    /** @return array{email:string, token:string, expiresAt:string}|null */
    public function attach(Business $business, BusinessRowInput $row, ?User $actor): ?array
    {
        $email = mb_strtolower(trim((string) $row->managerEmail));
        $firstName = trim((string) $row->managerFirstName);
        $lastName = trim((string) $row->managerLastName);
        $connection = $this->em->getConnection();

        // La migration ajoute un index unique sur LOWER(email) en plus de cet accès indexé.
        $id = $connection->fetchOne('SELECT id FROM users WHERE LOWER(email) = :email FOR UPDATE', ['email' => $email]);
        $user = $id === false ? null : $this->em->find(User::class, (int) $id);
        $invite = null;

        if ($user === null) {
            $user = (new User())->setEmail($email)->setRoles(['ROLE_GERANT'])->setIsActive(false);
            // Aucun mot de passe temporaire transmis ou réutilisable : le titulaire active son compte.
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(48))));
            $this->em->persist($user);
            $this->em->flush();
            $token = bin2hex(random_bytes(32));
            $expiresAt = new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC'));
            $connection->insert('user_profiles', [
                'user_id' => $user->getId(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $row->managerPhone,
                'job_title' => 'Gérant',
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
                'activated_at' => null,
                'created_at' => gmdate('Y-m-d H:i:sP'),
            ]);
            $invite = ['email' => $email, 'token' => $token, 'expiresAt' => $expiresAt->format(DATE_ATOM)];
        } else {
            // Ne jamais attribuer un commerce à un compte quelconque sur le seul critère e-mail.
            if (!in_array('ROLE_GERANT', $user->getRoles(), true)) {
                throw new \DomainException(sprintf('L’adresse %s existe mais n’est pas un compte gérant. Vérifiez son identité dans l’administration.', $email));
            }
            $profile = $connection->fetchAssociative(
                'SELECT first_name, last_name FROM user_profiles WHERE user_id = :uid FOR UPDATE',
                ['uid' => $user->getId()],
            );
            if ($profile === false || self::canonical($profile['first_name']) !== self::canonical($firstName)
                || self::canonical($profile['last_name']) !== self::canonical($lastName)) {
                throw new \DomainException(sprintf('Le compte %s existe, mais ses nom et prénom ne correspondent pas. Aucun accès accordé.', $email));
            }
        }

        $manager = $this->managerByUserId[$user->getId()]
            ?? $this->em->getRepository(Manager::class)->findOneBy(['user' => $user]);
        if ($manager === null) {
            $manager = (new Manager())
                ->setLastName($lastName)
                ->setFirstName($firstName)
                ->setPhone($row->managerPhone)
                ->setEmail($email)
                ->setUser($user);
            $this->em->persist($manager);
        }
        $this->managerByUserId[$user->getId()] = $manager;
        $this->em->persist(new BusinessManager($business, $manager));
        $access = $this->em->getRepository(MerchantBusinessAccess::class)
            ->findOneBy(['user' => $user, 'business' => $business]);
        if ($access === null) {
            $this->em->persist(new MerchantBusinessAccess($user, $business, $actor));
        } elseif ($access->getRevokedAt() !== null) {
            $access->grant($actor);
        }
        return $invite;
    }

    private static function canonical(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
