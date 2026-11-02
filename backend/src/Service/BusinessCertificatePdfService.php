<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Business;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BusinessCertificatePdfService
{
    public function __construct(
        private readonly BusinessQrCodeService $qr,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {}

    public function render(Business $business): string
    {
        $name = self::esc($business->getName());
        $reference = self::esc($this->qr->reference($business));
        $activity = self::esc($business->getActivityType()->getLabel());
        $district = self::esc($business->getDistrict() ?: 'Non renseigné');
        $address = self::esc($business->getAddress() ?: 'Non renseignée');
        $status = match ($business->getStatus()->value) {
            'active' => 'Actif', 'suspended' => 'Suspendu', 'closed' => 'Fermé', default => 'Inconnu',
        };
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan')))->format('d/m/Y');
        $qr = $this->qr->dataUri($business);
        $verificationUrl = self::esc($this->qr->verificationUrl($business));
        $warning = $this->environment !== 'prod' ? '<div class="test">DOCUMENT DE TEST — ENVIRONNEMENT DE DÉVELOPPEMENT</div>' : '';
        $html = <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<style>
@page { size: a5 portrait; margin: 16mm 13mm; }
body { font: 11px 'DejaVu Sans', sans-serif; color: #17352b; }
.flag { border-top: 7px solid #f77f00; border-bottom: 7px solid #168a45; height: 8px; background: white; }
.head { text-align: center; padding: 14px 0 9px; }
h1 { font-size: 20px; margin: 8px 0; } h2 { font-size: 13px; font-weight: normal; margin: 4px; }
.subtitle { color: #5e6b61; } .qr { text-align: center; margin: 14px auto; }
.qr img { width: 46mm; height: 46mm; } .ref { font-size: 14px; font-weight: bold; }
table { border-collapse: collapse; width: 100%; margin-top: 12px; } td { padding: 7px; border-bottom: 1px solid #e2e8e2; }
td:first-child { width: 31%; color: #58635c; } .footer { margin-top: 22px; font-size: 8.7px; color: #57665c; }
.url { word-wrap: break-word; font-size: 7px; } .test { padding: 8px; background: #fff1d6; font-weight: bold; text-align: center; }
</style></head><body>
<div class="flag"></div>
<div class="head"><h2>Gestion municipale des commerces — Attinguié</h2>
<h1>FICHE D'IDENTIFICATION DU COMMERCE</h1><p class="subtitle">Document de vérification — ne constitue pas une autorisation d'exercer</p></div>
{$warning}
<table><tr><td>Référence</td><td>{$reference}</td></tr>
<tr><td>Commerce</td><td>{$name}</td></tr>
<tr><td>Activité</td><td>{$activity}</td></tr>
<tr><td>Quartier</td><td>{$district}</td></tr>
<tr><td>Adresse</td><td>{$address}</td></tr>
<tr><td>Statut au {$date}</td><td>{$status}</td></tr></table>
<div class="qr"><img src="{$qr}" alt="QR Code du commerce"><div class="ref">{$reference}</div>
<p>Scannez ce QR Code pour vérifier le statut ACTUEL du commerce.</p>
<div class="url">{$verificationUrl}</div></div>
<div class="footer">Statut susceptible de changer à tout moment. Seule la consultation du site municipal fait foi pour la vérification en temps réel. Aucun renseignement financier ni personnel n'est inclus dans ce code.</div>
</body></html>
HTML;
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('a5', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        return $pdf->output();
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
