<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Profil administratif complémentaire : le compte de connexion reste dans users. */
#[ORM\Entity]
#[ORM\Table(name: 'user_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_user_profiles_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_user_profiles_invitation_hash', columns: ['invitation_token_hash'])]
class UserProfile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'first_name', length: 80)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', length: 80)]
    private string $lastName;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'job_title', length: 120, nullable: true)]
    private ?string $jobTitle = null;

    #[ORM\Column(name: 'invitation_token_hash', length: 64, nullable: true)]
    private ?string $invitationTokenHash = null;

    #[ORM\Column(name: 'invitation_expires_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invitationExpiresAt = null;

    #[ORM\Column(name: 'activated_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;
}
