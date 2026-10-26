<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentMethod;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payments')]
class Payment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Invoice $invoice;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 20, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    public function __construct(Invoice $invoice, string $amount, PaymentMethod $method)
    {
        $this->invoice = $invoice;
        $this->amount = $amount;
        $this->method = $method;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getAmount(): string { return $this->amount; }
    public function getMethod(): PaymentMethod { return $this->method; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(\DateTimeImmutable $v): static { $this->receivedAt = $v; return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $v): static { $this->reference = $v; return $this; }
}
