<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Grille de référence. Les périodicités autres que monthly ne sont pas facturées par le moteur actuel. */
#[ORM\Entity]
#[ORM\Table(name: 'activity_tariffs')]
#[ORM\UniqueConstraint(name: 'UNIQ_GC_TARIFF_ACTIVITY_PERIOD', columns: ['activity_type_id', 'period'])]
#[ORM\Index(name: 'IDX_GC_TARIFF_ACTIVITY', columns: ['activity_type_id'])]
class ActivityTariff
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ActivityType::class)]
    #[ORM\JoinColumn(name: 'activity_type_id', nullable: false, onDelete: 'RESTRICT')]
    private ActivityType $activityType;

    #[ORM\Column(length: 12)]
    private string $period;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency = 'XOF';

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $active = true;

    public function __construct(ActivityType $activityType, string $period, string $amount, bool $active = true)
    {
        $this->activityType = $activityType;
        $this->period = $period;
        $this->amount = $amount;
        $this->active = $active;
    }

    public function getId(): ?int { return $this->id; }
    public function getActivityType(): ActivityType { return $this->activityType; }
    public function getPeriod(): string { return $this->period; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $value): static { $this->amount = $value; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $value): static { $this->active = $value; return $this; }
}
