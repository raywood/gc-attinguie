<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Données figées d'un reçu : une ligne par paiement. */
#[ORM\Entity]
#[ORM\Table(name: 'receipts')]
#[ORM\UniqueConstraint(name: 'uniq_receipts_payment', columns: ['payment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_receipts_number', columns: ['number'])]
#[ORM\UniqueConstraint(name: 'uniq_receipts_token', columns: ['verification_token'])]
class Receipt
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Payment $payment;

    #[ORM\Column(length: 50)]
    private string $number;

    #[ORM\Column(name: 'verification_token', length: 64)]
    private string $verificationToken;

    #[ORM\Column(length: 64)]
    private string $signature;

    #[ORM\Column(length: 20)]
    private string $status = 'issued';

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'invoice_number', length: 30)]
    private string $invoiceNumber;

    #[ORM\Column(name: 'business_name', length: 180)]
    private string $businessName;

    #[ORM\Column(name: 'business_address', length: 255, nullable: true)]
    private ?string $businessAddress = null;

    #[ORM\Column(name: 'business_district', length: 120, nullable: true)]
    private ?string $businessDistrict = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'payment_method', length: 20)]
    private string $paymentMethod;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    public function getId(): ?int { return $this->id; }
    public function getPayment(): Payment { return $this->payment; }
    public function getNumber(): string { return $this->number; }
    public function getVerificationToken(): string { return $this->verificationToken; }
    public function getSignature(): string { return $this->signature; }
    public function getStatus(): string { return $this->status; }
    public function getIssuedAt(): \DateTimeImmutable { return $this->issuedAt; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getInvoiceNumber(): string { return $this->invoiceNumber; }
    public function getBusinessName(): string { return $this->businessName; }
    public function getBusinessAddress(): ?string { return $this->businessAddress; }
    public function getBusinessDistrict(): ?string { return $this->businessDistrict; }
    public function getAmount(): string { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getPaymentMethod(): string { return $this->paymentMethod; }
    public function getReference(): ?string { return $this->reference; }

    /** Format canonique utilisé pour signer les informations figées. */
    public function signaturePayload(): string
    {
        return json_encode([
            'paymentId' => $this->payment->getId(),
            'number' => $this->number,
            'token' => $this->verificationToken,
            'invoice' => $this->invoiceNumber,
            'business' => $this->businessName,
            'address' => $this->businessAddress,
            'district' => $this->businessDistrict,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->paymentMethod,
            'reference' => $this->reference,
            'receivedAt' => $this->receivedAt->format('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
