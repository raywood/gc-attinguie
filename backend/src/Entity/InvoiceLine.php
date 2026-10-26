<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_lines')]
class InvoiceLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column]
    private int $quantity = 1;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount;

    public function __construct(Invoice $invoice, string $label, string $unitPrice, int $quantity = 1)
    {
        $this->invoice = $invoice;
        $this->label = $label;
        $this->unitPrice = $unitPrice;
        $this->quantity = $quantity;
        $this->amount = bcmul($unitPrice, (string) $quantity, 2);
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getLabel(): string { return $this->label; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getAmount(): string { return $this->amount; }

    public function update(string $label, string $unitPrice, int $quantity): void
    {
        $this->label = $label;
        $this->unitPrice = $unitPrice;
        $this->quantity = $quantity;
        $this->amount = bcmul($unitPrice, (string) $quantity, 2);
        $this->invoice->recalculate();
    }
}
