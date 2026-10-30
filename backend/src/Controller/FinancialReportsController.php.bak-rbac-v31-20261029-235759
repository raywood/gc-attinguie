<?php
declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Exports financiers pour la mairie, en lecture seule. */
#[Route('/api/admin/reports/finance')]
#[IsGranted('ROLE_ADMIN')]
final class FinancialReportsController extends AbstractController
{
    /** @return array{0:string,1:string,2:string,3:string} */
    private function scope(Request $request): array
    {
        $period = trim((string) $request->query->get('period', ''));
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $period)) {
            throw new \InvalidArgumentException('Période invalide : utilisez YYYY-MM (2000 à 2099).');
        }
        $start = new \DateTimeImmutable($period . '-01 00:00:00', new \DateTimeZone('Africa/Abidjan'));
        return [
            $period,
            $start->format('Y-m-d'),
            $start->modify('+1 month')->format('Y-m-d'),
            (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan')))->format('Y-m-d'),
        ];
    }

    /** @return array<string,string> */
    private function invoiceParams(string $start, string $end, string $today): array
    {
        return ['startDate' => $start, 'endDate' => $end, 'today' => $today];
    }

    private function invoiceCte(): string
    {
        return <<<'SQL'
WITH payments_by_invoice AS (
    SELECT invoice_id, SUM(amount)::numeric(16,2) AS paid
    FROM payments
    GROUP BY invoice_id
), invoice_rows AS (
    SELECT i.id, i.number, i.period, i.status, i.due_at, i.currency,
           i.total::numeric(16,2) AS total,
           b.name AS business_name, b.district, a.label AS activity,
           COALESCE(p.paid, 0)::numeric(16,2) AS paid,
           GREATEST(i.total - COALESCE(p.paid, 0), 0)::numeric(16,2) AS balance
    FROM invoices i
    JOIN businesses b ON b.id = i.business_id
    JOIN activity_types a ON a.id = b.activity_type_id
    LEFT JOIN payments_by_invoice p ON p.invoice_id = i.id
    WHERE i.period >= :startDate AND i.period < :endDate
      AND i.status IN ('issued', 'paid') AND i.currency = 'XOF'
)
SQL;
    }

    /** @return array<string,mixed> */
    private function summary(Connection $db, array $params): array
    {
        return $db->fetchAssociative($this->invoiceCte() . <<<'SQL'
SELECT COUNT(*)::integer AS invoice_count,
       COALESCE(SUM(total),0)::numeric(16,2) AS billed,
       COALESCE(SUM(paid),0)::numeric(16,2) AS paid_on_invoices,
       COALESCE(SUM(balance),0)::numeric(16,2) AS remaining,
       COUNT(*) FILTER (WHERE balance > 0 AND due_at < :today)::integer AS overdue_count,
       COALESCE(SUM(balance) FILTER (WHERE balance > 0 AND due_at < :today),0)::numeric(16,2) AS overdue
FROM invoice_rows
SQL, $params) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function activities(Connection $db, array $params): array
    {
        return $db->fetchAllAssociative($this->invoiceCte() . <<<'SQL'
SELECT activity, COUNT(*)::integer AS invoice_count,
       COALESCE(SUM(total),0)::numeric(16,2) AS billed,
       COALESCE(SUM(paid),0)::numeric(16,2) AS collected,
       COALESCE(SUM(balance),0)::numeric(16,2) AS remaining,
       COALESCE(SUM(balance) FILTER (WHERE balance > 0 AND due_at < :today),0)::numeric(16,2) AS overdue
FROM invoice_rows
GROUP BY activity ORDER BY activity
SQL, $params);
    }

    /** @param list<string> $headers */
    private function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($headers, $rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                throw new \RuntimeException('Ouverture du flux CSV impossible.');
            }
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM pour Excel
            fputcsv($out, $headers, ';', '"', '');
            foreach ($rows as $row) {
                $safe = [];
                foreach (array_values($row) as $value) {
                    $v = (string) ($value ?? '');
                    // Neutralise l'injection de formules dans un tableur.
                    if (preg_match('/^[\x00-\x20]*[=+@\-]/u', $v)) {
                        $v = "'" . $v;
                    }
                    $safe[] = $v;
                }
                fputcsv($out, $safe, ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function invalidPeriod(): Response
    {
        return $this->json(['error' => 'Période invalide : utilisez YYYY-MM (2000-2099).'], 422);
    }

    #[Route('/invoices.csv', name: 'api_admin_finance_invoices_csv', methods: ['GET'])]
    public function invoicesCsv(Request $request, Connection $db): Response
    {
        try { [$period, $start, $end, $today] = $this->scope($request); }
        catch (\InvalidArgumentException) { return $this->invalidPeriod(); }
        $params = $this->invoiceParams($start, $end, $today);
        $rows = $db->fetchAllAssociative($this->invoiceCte() . <<<'SQL'
SELECT number, business_name, district, activity,
       to_char(period, 'YYYY-MM') AS period, status,
       to_char(due_at, 'YYYY-MM-DD') AS due_at,
       total, paid, balance,
       CASE WHEN balance > 0 AND due_at < :today THEN balance ELSE 0 END AS overdue,
       currency
FROM invoice_rows ORDER BY number
SQL, $params);
        return $this->csv("gc-attinguie-factures-$period.csv", [
            'Facture', 'Commerce', 'Quartier', 'Activité', 'Période', 'Statut',
            'Échéance', 'Facturé XOF', 'Encaissé XOF', 'Solde XOF', 'Échu XOF', 'Devise',
        ], $rows);
    }

    #[Route('/payments.csv', name: 'api_admin_finance_payments_csv', methods: ['GET'])]
    public function paymentsCsv(Request $request, Connection $db): Response
    {
        try { [$period, $start, $end] = $this->scope($request); }
        catch (\InvalidArgumentException) { return $this->invalidPeriod(); }
        $rows = $db->fetchAllAssociative(<<<'SQL'
SELECT p.id, i.number AS invoice, b.name AS business_name,
       to_char(p.received_at AT TIME ZONE 'Africa/Abidjan', 'YYYY-MM-DD HH24:MI:SS') AS received_at,
       p.method, p.reference, p.amount, i.currency,
       COALESCE(r.number, '') AS receipt_number,
       COALESCE(r.status, '') AS receipt_status
FROM payments p
JOIN invoices i ON i.id = p.invoice_id
JOIN businesses b ON b.id = i.business_id
LEFT JOIN receipts r ON r.payment_id = p.id
WHERE p.received_at >= :startCash AND p.received_at < :endCash
  AND i.currency = 'XOF'
ORDER BY p.received_at, p.id
SQL, ['startCash' => $start . ' 00:00:00+00:00', 'endCash' => $end . ' 00:00:00+00:00']);
        return $this->csv("gc-attinguie-encaissements-$period.csv", [
            'Paiement ID', 'Facture', 'Commerce', 'Date encaissement UTC', 'Mode',
            'Référence', 'Montant XOF', 'Devise', 'Numéro reçu', 'Statut reçu',
        ], $rows);
    }

    #[Route('/activities.csv', name: 'api_admin_finance_activities_csv', methods: ['GET'])]
    public function activitiesCsv(Request $request, Connection $db): Response
    {
        try { [$period, $start, $end, $today] = $this->scope($request); }
        catch (\InvalidArgumentException) { return $this->invalidPeriod(); }
        $rows = $this->activities($db, $this->invoiceParams($start, $end, $today));
        return $this->csv("gc-attinguie-activites-$period.csv", [
            'Activité', 'Factures', 'Facturé XOF', 'Encaissé sur factures XOF',
            'Reste XOF', 'Échu XOF',
        ], $rows);
    }

    #[Route('/summary.pdf', name: 'api_admin_finance_summary_pdf', methods: ['GET'])]
    public function summaryPdf(Request $request, Connection $db): Response
    {
        try { [$period, $start, $end, $today] = $this->scope($request); }
        catch (\InvalidArgumentException) { return $this->invalidPeriod(); }
        $params = $this->invoiceParams($start, $end, $today);
        $summary = $this->summary($db, $params);
        $activities = $this->activities($db, $params);
        $cash = $db->fetchAssociative(<<<'SQL'
SELECT COUNT(*)::integer AS payment_count,
       COALESCE(SUM(p.amount), 0)::numeric(16,2) AS amount
FROM payments p
JOIN invoices i ON i.id = p.invoice_id
WHERE p.received_at >= :startCash AND p.received_at < :endCash
  AND i.currency = 'XOF'
SQL, ['startCash' => $start . ' 00:00:00+00:00', 'endCash' => $end . ' 00:00:00+00:00']) ?: [];

        $h = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn (mixed $value): string => number_format((float) ($value ?? 0), 2, ',', ' ') . ' FCFA';
        $cells = '';
        foreach ($activities as $a) {
            $cells .= '<tr><td>' . $h($a['activity']) . '</td><td class="num">' . $h($a['invoice_count']) . '</td>'
                . '<td class="num">' . $money($a['billed']) . '</td><td class="num">' . $money($a['collected']) . '</td>'
                . '<td class="num">' . $money($a['remaining']) . '</td><td class="num">' . $money($a['overdue']) . '</td></tr>';
        }
        if ($cells === '') {
            $cells = '<tr><td colspan="6">Aucune facture émise sur cette période.</td></tr>';
        }
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>'
            . '@page{margin:25mm 16mm}body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#182033}'
            . 'h1{font-size:20px;margin:0 0 4px}h2{font-size:13px;margin:22px 0 8px}'
            . '.intro{color:#5c677b}.kpi{border:1px solid #d8deea;padding:10px;margin:6px 0}'
            . '.kpi strong{font-size:14px}table{width:100%;border-collapse:collapse;font-size:8px}'
            . 'th,td{padding:8px 5px;border-bottom:1px solid #dde2eb;text-align:left}'
            . 'thead{background:#e8edf6}td.num,th.num{text-align:right}'
            . '.note{margin-top:19px;font-size:9px;line-height:1.6;color:#475569}'
            . '</style></head><body><h1>GC-Attinguié — Rapport financier municipal</h1>'
            . '<div class="intro">Période de facturation : ' . $h($period) . ' · Situation des échéances au ' . $h($today) . ' (UTC)</div>'
            . '<h2>Synthèse</h2>'
            . '<div class="kpi">Facturé sur la période : <strong>' . $money($summary['billed'] ?? '0') . '</strong> (' . $h($summary['invoice_count'] ?? '0') . ' facture(s))</div>'
            . '<div class="kpi">Encaissé sur ces factures à ce jour : <strong>' . $money($summary['paid_on_invoices'] ?? '0') . '</strong></div>'
            . '<div class="kpi">Reste à recouvrer : <strong>' . $money($summary['remaining'] ?? '0') . '</strong></div>'
            . '<div class="kpi">Dont impayés échus : <strong>' . $money($summary['overdue'] ?? '0') . '</strong> (' . $h($summary['overdue_count'] ?? '0') . ' facture(s))</div>'
            . '<div class="kpi">Encaissements reçus pendant le mois (toutes périodes de facture) : <strong>' . $money($cash['amount'] ?? '0') . '</strong> (' . $h($cash['payment_count'] ?? '0') . ' paiement(s))</div>'
            . '<h2>Répartition par activité</h2><table><thead><tr><th>Activité</th><th class="num">Factures</th><th class="num">Facturé</th><th class="num">Encaissé</th><th class="num">Solde</th><th class="num">Échu</th></tr></thead><tbody>'
            . $cells . '</tbody></table>'
            . '<p class="note">Conventions : le montant « encaissé sur ces factures » couvre tous leurs paiements à ce jour, quel que soit leur mois de réception. Les « encaissements du mois » regroupent les paiements reçus pendant le mois affiché, même pour d’autres périodes de facture. Un reçu annulé ne constitue pas un remboursement : son paiement reste comptabilisé. Les brouillons sont exclus du montant facturé. Document de gestion interne, non certifié.</p>'
            . '</body></html>';
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $response = new Response($pdf->output());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="gc-attinguie-rapport-financier-'.$period.'.pdf"');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
