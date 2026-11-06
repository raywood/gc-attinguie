<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Un constat municipal journalier, valorisé au tarif en vigueur lors de la saisie. */
#[ORM\Entity]
#[ORM\Table(name: 'daily_activity_records')]
#[ORM\UniqueConstraint(name: 'UNIQ_GC_DAILY_BUSINESS_DATE', columns: ['business_id', 'activity_date'])]
#[ORM\Index(name: 'IDX_GC_DAILY_ACTIVITY_DATE', columns: ['activity_date'])]
class DailyActivityRecord
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Business::class)]
    #[ORM\JoinColumn(name: 'business_id', nullable: false, onDelete: 'RESTRICT')]
    private Business $business;

    #[ORM\Column(name: 'activity_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $activityDate;

    #[ORM\Column(name: 'unit_amount', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitAmount;

    #[ORM\Column(length: 3)]
    private string $currency = 'XOF';

    #[ORM\Column(length: 20)]
    private string $status = 'confirmed';

    #[ORM\Column(length: 250, nullable: true)]
    private ?string $note;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'observed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $observedBy;

    #[ORM\Column(name: 'observed_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $observedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'voided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $voidedBy = null;

    #[ORM\Column(name: 'voided_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(name: 'void_reason', length: 255, nullable: true)]
    private ?string $voidReason = null;

    public function __construct(Business $business, \DateTimeImmutable $date, string $amount, ?User $actor, ?string $note)
    {
        $this->business = $business;
        $this->activityDate = $date->setTime(0, 0);
        $this->unitAmount = $amount;
        $this->observedBy = $actor;
        $this->note = $note;
        $this->observedAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan'));
    }
    public function getId(): ?int { return $this->id; }
    public function getBusiness(): Business { return $this->business; }
    public function getActivityDate(): \DateTimeImmutable { return $this->activityDate; }
    public function getUnitAmount(): string { return $this->unitAmount; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getNote(): ?string { return $this->note; }
    public function getObservedAt(): \DateTimeImmutable { return $this->observedAt; }
    public function getVoidReason(): ?string { return $this->voidReason; }
    public function void(?User $by, string $reason): void
    {
        if ($this->status !== 'confirmed') throw new \LogicException('Constat déjà annulé.');
        $this->status = 'void';
        $this->voidedBy = $by;
        $this->voidReason = $reason;
        $this->voidedAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan'));
    }
}
