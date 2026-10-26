<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Historique des gérants d'un commerce (un seul gérant principal actif à la fois). */
#[ORM\Entity]
#[ORM\Table(name: 'business_managers')]
#[ORM\UniqueConstraint(
    name: 'uniq_business_primary_manager',
    columns: ['business_id'],
    options: ['where' => '((is_primary = true) AND (ended_at IS NULL))'],
)]

class BusinessManager
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'managers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Business $business;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Manager $manager;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column]
    private bool $isPrimary = true;

    public function __construct(Business $business, Manager $manager)
    {
        $this->business = $business;
        $this->manager = $manager;
        $this->startedAt = new \DateTimeImmutable('today');
    }

    public function getId(): ?int { return $this->id; }
    public function getBusiness(): Business { return $this->business; }
    public function getManager(): Manager { return $this->manager; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(\DateTimeImmutable $v): static { $this->startedAt = $v; return $this; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function setEndedAt(?\DateTimeImmutable $v): static { $this->endedAt = $v; return $this; }
    public function isPrimary(): bool { return $this->isPrimary; }
    public function setIsPrimary(bool $v): static { $this->isPrimary = $v; return $this; }
}
