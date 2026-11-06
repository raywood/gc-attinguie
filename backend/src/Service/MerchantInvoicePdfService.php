<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MerchantInvoicePdfService
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    public function render(Invoice $invoice): string
    {
        $number = self::e($invoice->getNumber());
        $business = self::e($invoice->getBusiness()->getName());
        $period = $invoice->getPeriod()->format('m/Y');
        $date = $invoice->getIssuedAt()?->setTimezone(new \DateTimeZone('Africa/Abidjan'))->format('d/m/Y') ?? '—';
        $due = $invoice->getDueAt()?->format('d/m/Y') ?? '—';
        $total = self::e($invoice->getTotal());
        $rows = '';
        foreach ($invoice->getLines() as $line) {
            $rows .= '<tr><td>'.self::e($line->getLabel()).'</td><td>'.(int)$line->getQuantity().'</td><td>'.self::e($line->getUnitPrice()).'</td><td>'.self::e($line->getAmount()).'</td></tr>';
        }
        $logo = '';
        $path = $this->projectDir.'/public/gc-attinguie-watermark-20.png';
        if (is_file($path) && ($data = file_get_contents($path)) !== false) {
            $logo = '<div class="wm"><img src="data:image/png;base64,'.base64_encode($data).'" alt=""></div>';
        }
        $html = <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
@page {size: a4; margin: 18mm;} body {font:11px "DejaVu Sans",sans-serif; color:#18382e;}
.wm {position:fixed; top:55mm; left:22mm; width:150mm; z-index:-1;} .wm img {width:100%;}
header {border-top:7px solid #e86d00; border-bottom:3px solid #087847; padding:12px 0;}
h1 {margin:8px 0;font-size:20px;} .muted {color:#566b61;} table {border-collapse:collapse;width:100%;margin-top:25px;}
th,td {border-bottom:1px solid #ccd7d1;padding:9px 6px;text-align:left;} th {background:#eff6f1;} .total{text-align:right;font-size:16px;font-weight:bold;margin-top:14px;}
footer {position:fixed;bottom:8mm;font-size:9px;color:#566b61;} .warn {font-weight:bold;margin-top:20px;}
</style></head><body>{$logo}<header><div>Gestion municipale des commerces — Attinguié</div>
<h1>FACTURE {$number}</h1><div class="muted">Émise le {$date} · Échéance : {$due} · Période : {$period}</div></header>
<p><strong>Commerce :</strong> {$business}</p>
<table><thead><tr><th>Désignation</th><th>Qté</th><th>Prix unitaire XOF</th><th>Montant XOF</th></tr></thead>
<tbody>{$rows}</tbody></table><div class="total">TOTAL : {$total} XOF</div>
<p class="warn">Les règlements et le solde actualisé figurent dans l’espace commerçant.</p>
<footer>Document de facturation émis par GC-ATTINGUIÉ · vérifiez les informations auprès de la mairie.</footer>
</body></html>
HTML;
        $options = new Options();
        $options->set('defaultFont','DejaVu Sans');
        $options->set('isRemoteEnabled',false);
        $options->set('isPhpEnabled',false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        return $pdf->output();
    }
    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
