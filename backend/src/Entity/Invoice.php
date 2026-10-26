<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\InvoiceStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invoices')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_business_period', columns: ['business_id', 'period'])]
#[ORM\Index(name: 'idx_invoices_status', columns: ['status'])]
class Invoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $number;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Business $business;

    /** Premier jour du mois facturé. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $period;

    #[ORM\Column(length: 20, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status = InvoiceStatus::Draft;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'XOF';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** @var Collection<int, InvoiceLine> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: Payment::class, cascade: ['persist'])]
    private Collection $payments;

    public function __construct(Business $business, \DateTimeImmutable $period)
    {
        $this->business = $business;
        $this->period = $period->modify('first day of this month')->setTime(0, 0);
        $this->lines = new ArrayCollection();
        $this->payments = new ArrayCollection();
    }

    public function isEditable(): bool { return $this->status === InvoiceStatus::Draft; }

    public function addLine(InvoiceLine $line): static
    {
        if (!$this->isEditable()) {
            throw new \LogicException('Seule une facture en brouillon peut être modifiée.');
        }
        $this->lines->add($line);
        $this->recalculate();
        return $this;
    }

    public function recalculate(): void
    {
        $sum = '0.00';
        foreach ($this->lines as $line) {
            $sum = bcadd($sum, $line->getAmount(), 2);
        }
        $this->subtotal = $sum;
        $this->total = $sum;
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): string { return $this->number; }
    public function setNumber(string $v): static { $this->number = $v; return $this; }
    public function getBusiness(): Business { return $this->business; }
    public function getPeriod(): \DateTimeImmutable { return $this->period; }
    public function getStatus(): InvoiceStatus { return $this->status; }
    public function setStatus(InvoiceStatus $v): static { $this->status = $v; return $this; }
    public function getSubtotal(): string { return $this->subtotal; }
    public function getTotal(): string { return $this->total; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $v): static { $this->currency = $v; return $this; }
    public function getIssuedAt(): ?\DateTimeImmutable { return $this->issuedAt; }
    public function setIssuedAt(?\DateTimeImmutable $v): static { $this->issuedAt = $v; return $this; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(?\DateTimeImmutable $v): static { $this->dueAt = $v; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $v): static { $this->createdBy = $v; return $this; }
    /** @return Collection<int, InvoiceLine> */
    public function getLines(): Collection { return $this->lines; }
    /** @return Collection<int, Payment> */
    public function getPayments(): Collection { return $this->payments; }
}
