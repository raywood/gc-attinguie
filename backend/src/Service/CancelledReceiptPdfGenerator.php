<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Receipt;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Copie administrative d'un reçu révoqué, générée en lecture seule à partir
 * des données figées de receipts. Ce n'est pas une archive du PDF original.
 */
final class CancelledReceiptPdfGenerator
{
    public function __construct(private readonly ReceiptIssuer $issuer)
    {
    }

    public function render(Receipt $receipt): string
    {
        if ($receipt->getStatus() !== 'cancelled') {
            throw new \LogicException('Seuls les reçus annulés disposent de cette copie administrative.');
        }
        if (!$this->issuer->isAuthentic($receipt)) {
            throw new \LogicException('Signature du reçu invalide : consultation refusée.');
        }

        $cancelledAt = $receipt->getCancelledAt();
        $reason = $receipt->getCancellationReason();
        $cancelledBy = $receipt->getCancelledByEmail();
        if ($cancelledAt === null || $reason === null || trim($reason) === '' || $cancelledBy === null || trim($cancelledBy) === '') {
            throw new \LogicException('Historique d’annulation incomplet : consultation refusée.');
        }

        // Le QR conserve son token historique : la vérification publique dit "cancelled".
        $verificationUrl = $this->issuer->verificationUrl($receipt);
        $qrCode = new QrCode(data: $verificationUrl, size: 220, margin: 12);
        $qrDataUri = (new PngWriter())->write($qrCode)->getDataUri();

        $number = self::escape($receipt->getNumber());
        $invoice = self::escape($receipt->getInvoiceNumber());
        $business = self::escape($receipt->getBusinessName());
        $address = self::escape($receipt->getBusinessAddress() ?: 'Non renseignée');
        $district = self::escape($receipt->getBusinessDistrict() ?: 'Non renseigné');
        $amount = self::escape(self::amount($receipt->getAmount()));
        $currency = self::escape($receipt->getCurrency());
        $method = self::escape($receipt->getPaymentMethod());
        $reference = self::escape($receipt->getReference() ?: 'Non renseignée');
        $received = self::escape(self::dateAbidjan($receipt->getReceivedAt()));
        $issued = self::escape(self::dateAbidjan($receipt->getIssuedAt()));
        $cancelled = self::escape(self::dateAbidjan($cancelledAt));
        $reasonHtml = nl2br(self::escape($reason), false);
        $cancelledByHtml = self::escape($cancelledBy);
        $verification = self::escape($verificationUrl);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);

        $html = <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="UTF-8">
<style>
@page { margin: 36px 42px; }
body { font-family: 'DejaVu Sans', sans-serif; color:#182338; font-size:11px; line-height:1.45; }
.header { padding-bottom:12px; border-bottom:3px solid #a32929; }
.header small { color:#526074; text-transform:uppercase; letter-spacing:1px; }
h1 { margin:7px 0 3px; color:#7b1f1f; font-size:23px; }
.notice { border:2px solid #9c2424; padding:12px 14px; color:#842222; background:#fff0f0; margin:17px 0; }
.notice strong { font-size:18px; letter-spacing:2px; }
section { margin-top:20px; }
h2 { font-size:13px; margin:0 0 8px; }
table { border-collapse:collapse; width:100%; }
th,td { border:1px solid #d7dce4; padding:8px; vertical-align:top; }
th { width:37%; text-align:left; color:#465168; background:#f1f3f7; font-weight:normal; }
.reason { word-wrap:break-word; overflow-wrap:break-word; }
.qr { text-align:center; margin-top:18px; }
.qr img { width:110px; height:110px; }
.qr small { color:#5d6572; font-size:8px; word-wrap:break-word; }
.footer { border-top:1px solid #d4dae3; margin-top:20px; padding-top:10px; color:#566073; font-size:9px; }
</style></head><body>
<div class="header"><small>Gestion municipale des commerces — Attinguié</small>
<h1>CONSULTATION ADMINISTRATIVE — REÇU ANNULÉ</h1>
<p>Reçu n° {$number}</p></div>
<div class="notice"><strong>ANNULÉ — SANS VALEUR JUSTIFICATIVE</strong><br>
Ce document ne doit pas être utilisé comme preuve d'un reçu valide ni comme demande de remboursement.
Le paiement et la facture d'origine demeurent enregistrés.</div>
<section><h2>Données du reçu lors de son émission</h2>
<table>
<tr><th>Numéro du reçu</th><td>{$number}</td></tr>
<tr><th>Facture</th><td>{$invoice}</td></tr>
<tr><th>Commerce</th><td>{$business}</td></tr>
<tr><th>Adresse / quartier</th><td>{$address} — {$district}</td></tr>
<tr><th>Montant encaissé</th><td><strong>{$amount} {$currency}</strong></td></tr>
<tr><th>Mode / référence</th><td>{$method} — {$reference}</td></tr>
<tr><th>Date d'encaissement</th><td>{$received}</td></tr>
<tr><th>Date d'émission</th><td>{$issued}</td></tr>
</table></section>
<section><h2>Historique de révocation</h2>
<table>
<tr><th>État actuel</th><td><strong>ANNULÉ</strong></td></tr>
<tr><th>Date d'annulation</th><td>{$cancelled}</td></tr>
<tr><th>Administrateur</th><td>{$cancelledByHtml}</td></tr>
<tr><th>Motif enregistré</th><td class="reason">{$reasonHtml}</td></tr>
</table></section>
<div class="qr"><img src="{$qrDataUri}" alt="QR de vérification"><br>
<small>Ce QR doit indiquer « Reçu annulé » sur la page publique.<br>{$verification}</small></div>
<div class="footer">Copie administrative reconstituée depuis les champs conservés en base de données ;
ce fichier n'est pas une archive du PDF original émis. Consultation réservée à l'administration.
L'annulation du justificatif n'annule pas le mouvement financier.</div>
</body></html>
HTML;

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        // Marquage sur toutes les pages, même si le motif crée une 2e page.
        // Pas de script PHP embarqué : callback Canvas côté serveur.
        $pdf->getCanvas()->page_script(static function (
            int $pageNumber,
            int $pageCount,
            Canvas $canvas,
            FontMetrics $fontMetrics,
        ): void {
            $font = $fontMetrics->getFont('helvetica', 'bold');
            $canvas->text(105, 424, 'ANNULE', $font, 78, [0.76, 0.10, 0.10, 'alpha' => 0.14], 0.0, 0.0, -30.0);
        });

        return $pdf->output();
    }

    private static function dateAbidjan(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Africa/Abidjan'))->format('d/m/Y à H:i') . ' (Abidjan)';
    }

    private static function amount(string $amount): string
    {
        return number_format((float) $amount, str_ends_with($amount, '.00') ? 0 : 2, ',', ' ');
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
