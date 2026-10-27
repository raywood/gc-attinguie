<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Payment;
use App\Entity\Receipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Émet une seule fois le reçu d'un paiement et vérifie la signature de ses données figées. */
final class ReceiptIssuer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(RECEIPT_SIGNING_KEY)%')]
        private readonly string $signingKey,
        #[Autowire('%env(RECEIPT_VERIFY_BASE_URL)%')]
        private readonly string $baseUrl,
    ) {
    }

    public function issueForPayment(Payment $payment): Receipt
    {
        $paymentId = $payment->getId();
        if ($paymentId === null) {
            throw new \LogicException('Le paiement doit être enregistré avant émission du reçu.');
        }
        $this->assertSigningKey();
        $existing = $this->em->getRepository(Receipt::class)->findOneBy(['payment' => $payment]);
        if ($existing instanceof Receipt) {
            return $existing;
        }

        $invoice = $payment->getInvoice();
        $business = $invoice->getBusiness();
        $timezone = new \DateTimeZone('Africa/Abidjan');
        $number = sprintf('REC-%s-%06d', $payment->getReceivedAt()->setTimezone($timezone)->format('Y'), $paymentId);
        $token = bin2hex(random_bytes(32));
        $issuedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $receivedAt = $payment->getReceivedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        // Le token et les champs financiers deviennent immuables après création.
        // ON CONFLICT assure l'idempotence même en cas de deux téléchargements simultanés.
        $data = [
            'payment_id' => $paymentId,
            'number' => $number,
            'verification_token' => $token,
            'status' => 'issued',
            'issued_at' => $issuedAt,
            'received_at' => $receivedAt,
            'invoice_number' => $invoice->getNumber(),
            'business_name' => $business->getName(),
            'business_address' => $business->getAddress(),
            'business_district' => $business->getDistrict(),
            'amount' => $payment->getAmount(),
            'currency' => $invoice->getCurrency(),
            'payment_method' => $payment->getMethod()->value,
            'reference' => $payment->getReference(),
        ];

        $canonical = json_encode([
            'paymentId' => $paymentId,
            'number' => $number,
            'token' => $token,
            'invoice' => $data['invoice_number'],
            'business' => $data['business_name'],
            'address' => $data['business_address'],
            'district' => $data['business_district'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'method' => $data['payment_method'],
            'reference' => $data['reference'],
            'receivedAt' => $receivedAt,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $canonical, $this->signingKey);
        $this->em->getConnection()->executeStatement(<<<'SQL'
INSERT INTO receipts
(payment_id, number, verification_token, signature, status, issued_at, received_at,
 invoice_number, business_name, business_address, business_district, amount, currency, payment_method, reference)
VALUES
(:payment_id, :number, :verification_token, :signature, :status, :issued_at, :received_at,
 :invoice_number, :business_name, :business_address, :business_district, :amount, :currency, :payment_method, :reference)
ON CONFLICT (payment_id) DO NOTHING
SQL, [...$data, 'signature' => $signature]);

        $receipt = $this->em->getRepository(Receipt::class)->findOneBy(['payment' => $payment]);
        if (!$receipt instanceof Receipt) {
            throw new \RuntimeException('Impossible de retrouver le reçu après son enregistrement.');
        }
        return $receipt;
    }

    public function isAuthentic(Receipt $receipt): bool
    {
        $this->assertSigningKey();
        return hash_equals(
            $receipt->getSignature(),
            hash_hmac('sha256', $receipt->signaturePayload(), $this->signingKey),
        );
    }

    public function verificationUrl(Receipt $receipt): string
    {
        $base = rtrim(trim($this->baseUrl), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $base)) {
            throw new \LogicException('RECEIPT_VERIFY_BASE_URL doit être une URL HTTP(S) valide.');
        }
        return $base . '/api/receipts/verify/' . $receipt->getVerificationToken();
    }

    private function assertSigningKey(): void
    {
        if (strlen($this->signingKey) < 32) {
            throw new \LogicException('RECEIPT_SIGNING_KEY doit contenir au moins 32 caractères aléatoires.');
        }
    }
}
