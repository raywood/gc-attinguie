<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'activity_types')]
class ActivityType
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $label;

    /** Tarif mensuel fixe (string : decimal PostgreSQL, jamais float). */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $monthlyFee = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'XOF';

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $v): static { $this->code = $v; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $v): static { $this->label = $v; return $this; }
    public function getMonthlyFee(): string { return $this->monthlyFee; }
    public function setMonthlyFee(string $v): static { $this->monthlyFee = $v; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $v): static { $this->currency = $v; return $this; }
}
