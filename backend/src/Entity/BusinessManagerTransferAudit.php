<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Journal des transferts. Les identifiants et les e-mails sont figés pour l'audit. */
#[ORM\Entity]
#[ORM\Table(name: 'business_manager_transfer_audit')]
#[ORM\Index(name: 'IDX_GC_BMTA_BUSINESS_TIME', columns: ['business_id', 'changed_at'])]
final class BusinessManagerTransferAudit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Business::class)]
    #[ORM\JoinColumn(name: 'business_id', nullable: false, onDelete: 'RESTRICT')]
    private Business $business;

    #[ORM\Column(name: 'old_manager_id', nullable: true)]
    private ?int $oldManagerId;

    #[ORM\Column(name: 'new_manager_id')]
    private int $newManagerId;

    #[ORM\Column(name: 'old_user_id', nullable: true)]
    private ?int $oldUserId;

    #[ORM\Column(name: 'new_user_id')]
    private int $newUserId;

    #[ORM\Column(name: 'old_manager_name', length: 220, nullable: true)]
    private ?string $oldManagerName;

    #[ORM\Column(name: 'new_manager_name', length: 220)]
    private string $newManagerName;

    #[ORM\Column(name: 'old_email', length: 180, nullable: true)]
    private ?string $oldEmail;

    #[ORM\Column(name: 'new_email', length: 180)]
    private string $newEmail;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(name: 'changed_by_id')]
    private int $changedById;

    #[ORM\Column(name: 'changed_by_email', length: 180)]
    private string $changedByEmail;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    public function __construct(
        Business $business,
        ?Manager $previousManager,
        Manager $newManager,
        ?User $previousUser,
        User $newUser,
        User $actor,
        string $reason,
    ) {
        $this->business = $business;
        $this->oldManagerId = $previousManager?->getId();
        $this->newManagerId = (int) $newManager->getId();
        $this->oldUserId = $previousUser?->getId();
        $this->newUserId = (int) $newUser->getId();
        $this->oldManagerName = $previousManager?->getFullName();
        $this->newManagerName = $newManager->getFullName();
        $this->oldEmail = $previousUser?->getEmail() ?? $previousManager?->getEmail();
        $this->newEmail = $newUser->getEmail();
        $this->reason = $reason;
        $this->changedById = (int) $actor->getId();
        $this->changedByEmail = $actor->getEmail();
        $this->changedAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan'));
    }

    public function getId(): ?int { return $this->id; }
    public function getOldManagerName(): ?string { return $this->oldManagerName; }
    public function getNewManagerName(): string { return $this->newManagerName; }
    public function getOldEmail(): ?string { return $this->oldEmail; }
    public function getNewEmail(): string { return $this->newEmail; }
    public function getReason(): string { return $this->reason; }
    public function getChangedByEmail(): string { return $this->changedByEmail; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
