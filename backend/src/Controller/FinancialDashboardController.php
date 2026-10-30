<?php
declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Tableau financier municipal : agrégations en lecture seule, montants en XOF. */
#[Route('/api/admin/dashboard')]
#[IsGranted('finance.read')]
final class FinancialDashboardController extends AbstractController
{
    #[Route('/financial', name: 'api_admin_financial_dashboard', methods: ['GET'])]
    public function financial(Request $request, Connection $db): JsonResponse
    {
        $abidjan = new \DateTimeZone('Africa/Abidjan');
        $period = trim((string) $request->query->get('period', (new \DateTimeImmutable('now', $abidjan))->format('Y-m')));
        if (!preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/D', $period)) {
            return $this->json(['error' => 'Période invalide. Utilisez YYYY-MM (année 2000-2099).'], 422);
        }

        $start = new \DateTimeImmutable($period . '-01 00:00:00', $abidjan);
        $next = $start->modify('+1 month');
        $historyStart = $start->modify('-5 months');
        $today = (new \DateTimeImmutable('now', $abidjan))->format('Y-m-d');
        $baseParams = [
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $next->format('Y-m-d'),
            'today' => $today,
            'currency' => 'XOF',
        ];

        // Un paiement est agrégé UNE SEULE FOIS par facture. Une annulation de reçu
        // ne supprime pas le paiement : les encaissements restent comptabilisés.
        $invoiceCte = <<<'SQL'
WITH payments_by_invoice AS (
    SELECT p.invoice_id, SUM(p.amount)::numeric(16,2) AS paid
    FROM payments p
    GROUP BY p.invoice_id
), invoice_rows AS (
    SELECT i.id, i.total::numeric(16,2) AS total, i.due_at,
           b.activity_type_id,
           COALESCE(p.paid, 0)::numeric(16,2) AS paid,
           GREATEST(i.total - COALESCE(p.paid, 0), 0)::numeric(16,2) AS balance
    FROM invoices i
    JOIN businesses b ON b.id = i.business_id
    LEFT JOIN payments_by_invoice p ON p.invoice_id = i.id
    WHERE i.period >= :startDate AND i.period < :endDate
      AND i.status IN ('issued', 'paid') AND i.currency = :currency
)
SQL;

        $summary = $db->fetchAssociative($invoiceCte . <<<'SQL'
SELECT COUNT(*)::integer AS invoice_count,
       COALESCE(SUM(total), 0)::numeric(16,2) AS billed,
       COALESCE(SUM(paid), 0)::numeric(16,2) AS paid_on_period_invoices,
       COALESCE(SUM(balance), 0)::numeric(16,2) AS remaining,
       COUNT(*) FILTER (WHERE balance > 0)::integer AS outstanding_count,
       COUNT(*) FILTER (WHERE balance > 0 AND due_at < :today)::integer AS overdue_count,
       COALESCE(SUM(balance) FILTER (WHERE balance > 0 AND due_at < :today), 0)::numeric(16,2) AS overdue_amount
FROM invoice_rows
SQL, $baseParams);

        // Encaissement du mois : DATE DU PAIEMENT, quelle que soit la période
        // de facture. Ne pas déduire les justificatifs annulés (pas de remboursement).
        $cashParams = [
            'startCash' => $start->format('Y-m-d H:i:sP'),
            'endCash' => $next->format('Y-m-d H:i:sP'),
            'currency' => 'XOF',
        ];
        $cash = $db->fetchAssociative(<<<'SQL'
SELECT COUNT(*)::integer AS payment_count,
       COALESCE(SUM(p.amount), 0)::numeric(16,2) AS amount
FROM payments p
JOIN invoices i ON i.id = p.invoice_id
WHERE p.received_at >= :startCash AND p.received_at < :endCash
  AND i.currency = :currency
SQL, $cashParams);

        // Répartition des factures sélectionnées, même si un paiement est reçu
        // hors du mois sélectionné. Chaque facture apparaît une seule fois.
        $categories = $db->fetchAllAssociative($invoiceCte . <<<'SQL'
SELECT a.code AS activity_code, a.label AS activity_label,
       COUNT(ir.id)::integer AS invoice_count,
       COALESCE(SUM(ir.total), 0)::numeric(16,2) AS billed,
       COALESCE(SUM(ir.paid), 0)::numeric(16,2) AS paid,
       COALESCE(SUM(ir.balance), 0)::numeric(16,2) AS remaining,
       COALESCE(SUM(ir.balance) FILTER (WHERE ir.due_at < :today), 0)::numeric(16,2) AS overdue
FROM invoice_rows ir
JOIN activity_types a ON a.id = ir.activity_type_id
GROUP BY a.id, a.code, a.label
ORDER BY billed DESC, a.label ASC
SQL, $baseParams);

        // Série temporelle des recettes encaissées : 6 mois glissants jusqu'à
        // la période sélectionnée, sans montant fictif pour les mois vides.
        $cashTrendRaw = $db->fetchAllAssociative(<<<'SQL'
SELECT to_char(date_trunc('month', p.received_at AT TIME ZONE 'Africa/Abidjan'), 'YYYY-MM') AS month,
       COALESCE(SUM(p.amount), 0)::numeric(16,2) AS amount
FROM payments p
JOIN invoices i ON i.id = p.invoice_id
WHERE p.received_at >= :historyStart AND p.received_at < :endCash
  AND i.currency = :currency
GROUP BY 1 ORDER BY 1
SQL, [
            'historyStart' => $historyStart->format('Y-m-d H:i:sP'),
            'endCash' => $next->format('Y-m-d H:i:sP'),
            'currency' => 'XOF',
        ]);
        $trendsByMonth = [];
        foreach ($cashTrendRaw as $row) {
            $trendsByMonth[(string) $row['month']] = (string) $row['amount'];
        }
        $trend = [];
        for ($n = 5; $n >= 0; --$n) {
            $key = $start->modify('-' . $n . ' months')->format('Y-m');
            $trend[] = ['month' => $key, 'amount' => $trendsByMonth[$key] ?? '0.00'];
        }

        $byActivity = [];
        foreach ($categories as $row) {
            $byActivity[] = [
                'code' => (string) $row['activity_code'],
                'label' => (string) $row['activity_label'],
                'invoiceCount' => (int) $row['invoice_count'],
                'billed' => (string) $row['billed'],
                'collected' => (string) $row['paid'],
                'remaining' => (string) $row['remaining'],
                'overdue' => (string) $row['overdue'],
            ];
        }

        return $this->json([
            'period' => $period,
            'currency' => 'XOF',
            'asOf' => $today,
            'summary' => [
                'billed' => (string) ($summary['billed'] ?? '0.00'),
                'cashCollected' => (string) ($cash['amount'] ?? '0.00'),
                'collectedOnPeriodInvoices' => (string) ($summary['paid_on_period_invoices'] ?? '0.00'),
                'remaining' => (string) ($summary['remaining'] ?? '0.00'),
                'overdue' => (string) ($summary['overdue_amount'] ?? '0.00'),
                'invoiceCount' => (int) ($summary['invoice_count'] ?? 0),
                'paymentCount' => (int) ($cash['payment_count'] ?? 0),
                'outstandingInvoiceCount' => (int) ($summary['outstanding_count'] ?? 0),
                'overdueInvoiceCount' => (int) ($summary['overdue_count'] ?? 0),
            ],
            'byActivity' => $byActivity,
            'cashTrend' => $trend,
            'definitions' => [
                'cashCollected' => 'Somme des paiements reçus durant le mois sélectionné, toutes périodes de facture confondues.',
                'billed' => 'Somme des factures de la période au statut issued ou paid (brouillons exclus).',
                'collectedOnPeriodInvoices' => 'Paiements enregistrés à ce jour sur les factures de la période, quelle que soit la date du paiement.',
                'remaining' => 'Somme des soldes positifs des factures de la période au statut issued ou paid.',
                'overdue' => 'Part du reste à payer dont la date d’échéance est strictement antérieure à la date du jour à Abidjan.',
                'cancelledReceipt' => 'Annuler un reçu ne supprime pas le paiement ni l’encaissement correspondant.',
            ],
        ]);
    }
}
