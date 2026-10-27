<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Payment;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use App\Enum\PaymentMethod;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Un reçu par paiement. Le numéro est stable car l'identifiant du paiement
 * est unique et ne change pas. Le token est persistant dans receipts.
 *
 * ATTENTION : le schéma actuel des paiements n'historise pas l'agent encaisseur.
 * Ce reçu de développement ne prétend donc pas identifier cet agent.
 */
final class ReceiptPdfGenerator
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        private readonly ReceiptIssuer $issuer,
    ) {
    }

    public function receiptNumber(Payment $payment): string
    {
        $id = $payment->getId();
        if ($id === null) {
            throw new \LogicException('Un reçu nécessite un paiement enregistré.');
        }

        // L'ID est unique à l'échelle de la base : pas de collision entre années.
        return sprintf(
            'REC-%s-%06d',
            $payment->getReceivedAt()->setTimezone(new \DateTimeZone('Africa/Abidjan'))->format('Y'),
            $id,
        );
    }

    public function render(Payment $payment): string
    {
        // L'émission est idempotente : même reçu/token sur tous les téléchargements.
        $receipt = $this->issuer->issueForPayment($payment);
        if (!$this->issuer->isAuthentic($receipt) || $receipt->getStatus() !== 'issued') {
            throw new \LogicException('Ce reçu est annulé ou son intégrité est compromise.');
        }
        $verificationUrl = $this->issuer->verificationUrl($receipt);
        $qrCode = new QrCode(data: $verificationUrl, size: 220, margin: 12);
        $qrCodeDataUri = (new PngWriter())->write($qrCode)->getDataUri();

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');

        $number = self::escape($receipt->getNumber());
        $invoiceNumber = self::escape($receipt->getInvoiceNumber());
        $name = self::escape($receipt->getBusinessName());
        $address = self::escape($receipt->getBusinessAddress() ?: 'Non renseignée');
        $district = self::escape($receipt->getBusinessDistrict() ?: 'Non renseigné');
        $reference = self::escape($receipt->getReference() ?: 'Non renseignée');
        $method = self::escape($this->methodLabel(PaymentMethod::from($receipt->getPaymentMethod())));
        $amount = self::escape(self::formatAmount($receipt->getAmount()));
        $invoiceTotal = self::escape(self::formatAmount($payment->getInvoice()->getTotal()));
        $currency = self::escape($receipt->getCurrency());
        $date = $receipt->getReceivedAt()
            ->setTimezone(new \DateTimeZone('Africa/Abidjan'))
            ->format('d/m/Y à H:i');
        $date = self::escape($date);
        $period = self::escape($payment->getInvoice()->getPeriod()->format('m/Y'));
        $verificationUrlEscaped = self::escape($verificationUrl);
        $demo = $this->environment !== 'prod'
            ? '<p class="demo">DOCUMENT DE TEST — ne constitue pas une quittance officielle</p>'
            : '';

        // Les valeurs provenant de la base sont échappées avant insertion HTML.
        // Aucun CSS, script, image ou police distante n'est autorisé.
        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8">
<style>
@page { margin: 35px 42px; }
body { font-family: 'DejaVu Sans', sans-serif; color: #17233b; font-size: 11px; line-height: 1.5; }
.header { border-bottom: 3px solid #c69c49; padding-bottom: 14px; margin-bottom: 24px; }
.header-small { font-size: 10px; text-transform: uppercase; letter-spacing: 1.3px; color: #5a6475; }
h1 { font-size: 25px; margin: 8px 0 2px; color: #102448; }
.subtitle { color: #5a6475; margin: 0; }
.demo { padding: 9px; text-align: center; background: #fff5d9; border: 1px solid #e0bf63; font-weight: bold; }
.details { border-collapse: collapse; width: 100%; margin-top: 19px; }
.details th { width: 36%; text-align: left; background: #eff2f6; color: #3c4861; font-weight: normal; }
.details td, .details th { padding: 10px; border: 1px solid #dbe0e8; }
.amount { margin-top: 26px; background: #f2f5fa; padding: 18px; text-align: center; border-top: 2px solid #c69c49; }
.amount .label { font-size: 11px; color: #48566d; }
.amount .value { font-size: 23px; font-weight: bold; color: #102448; margin-top: 7px; }
.qr { text-align: center; margin: 20px 0 0; }
.qr img { width: 128px; height: 128px; }
.qr p { font-size: 9px; color: #3c4861; }
.footer { font-size: 9px; border-top: 1px solid #dbe0e8; padding-top: 12px; margin-top: 35px; color: #5a6475; }
</style></head><body>
<div class="header">
  <div class="header-small">Gestion municipale des commerces — Attinguié</div>
  <h1>REÇU D'ENCAISSEMENT</h1>
  <p class="subtitle">Reçu n° {$number}</p>
</div>
{$demo}
<p>Ce document constate <strong>un paiement enregistré</strong> sur la facture ci-dessous.</p>
<table class="details">
<tr><th>Reçu</th><td>{$number}</td></tr>
<tr><th>Date de l'encaissement</th><td>{$date} (heure d'Abidjan)</td></tr>
<tr><th>Commerce</th><td>{$name}</td></tr>
<tr><th>Adresse</th><td>{$address}</td></tr>
<tr><th>Quartier</th><td>{$district}</td></tr>
<tr><th>Facture associée</th><td>{$invoiceNumber}</td></tr>
<tr><th>Période facturée</th><td>{$period}</td></tr>
<tr><th>Total de la facture</th><td>{$invoiceTotal} {$currency}</td></tr>
<tr><th>Mode de paiement</th><td>{$method}</td></tr>
<tr><th>Référence du versement</th><td>{$reference}</td></tr>
</table>
<div class="amount"><div class="label">MONTANT REÇU POUR CET ENCAISSEMENT</div>
<div class="value">{$amount} {$currency}</div></div>
<div class="qr">
  <img src="{$qrCodeDataUri}" alt="QR de vérification">
  <p>Scannez pour vérifier l'authenticité de ce reçu.<br>{$verificationUrlEscaped}</p>
</div>
<div class="footer">
  Reçu signé et enregistré ; les informations peuvent être vérifiées sur le site municipal.
  Un paiement partiel donne lieu à un reçu distinct.
  Le système actuel n'historise pas l'identité de l'agent encaisseur : ne pas utiliser
  comme justificatif officiel avant ajout de l'audit et des contrôles réglementaires.
</div>
</body></html>
HTML;

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function formatAmount(string $value): string
    {
        $decimals = str_ends_with($value, '.00') ? 0 : 2;
        return number_format((float) $value, $decimals, ',', ' ');
    }

    private function methodLabel(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Cash => 'Espèces',
            PaymentMethod::MobileMoney => 'Mobile Money',
            PaymentMethod::BankTransfer => 'Virement bancaire',
            PaymentMethod::Cheque => 'Chèque',
        };
    }
}
