<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Profil daté. Ne jamais écraser une ligne historique : en ajouter une à date future. */
#[ORM\Entity]
#[ORM\Table(name: 'business_billing_profiles')]
#[ORM\UniqueConstraint(name: 'UNIQ_GC_BILLING_BUSINESS_EFFECTIVE', columns: ['business_id', 'effective_from'])]
#[ORM\Index(name: 'IDX_GC_BILLING_BUSINESS_DATE', columns: ['business_id', 'effective_from'])]
class BusinessBillingProfile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Business::class)]
    #[ORM\JoinColumn(name: 'business_id', nullable: false, onDelete: 'RESTRICT')]
    private Business $business;

    #[ORM\Column(length: 12)]
    private string $frequency;

    #[ORM\Column(name: 'effective_from', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $effectiveFrom;

    #[ORM\Column(length: 255)]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Business $business, string $frequency, \DateTimeImmutable $effectiveFrom, string $reason, ?User $actor)
    {
        if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            throw new \InvalidArgumentException('Périodicité inconnue.');
        }
        $this->business = $business;
        $this->frequency = $frequency;
        $this->effectiveFrom = $effectiveFrom->setTime(0, 0);
        $this->reason = $reason;
        $this->createdBy = $actor;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan'));
    }

    public function getId(): ?int { return $this->id; }
    public function getBusiness(): Business { return $this->business; }
    public function getFrequency(): string { return $this->frequency; }
    public function getEffectiveFrom(): \DateTimeImmutable { return $this->effectiveFrom; }
    public function getReason(): string { return $this->reason; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
