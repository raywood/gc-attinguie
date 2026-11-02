<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Business;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Données financières privées : accès uniquement aux agents habilités, jamais via l'URL publique du QR. */
final class BusinessFinancialSummaryController extends AbstractController
{
    #[Route('/api/businesses/{id<\d+>}/financial-summary', name: 'business_financial_summary', methods: ['GET'])]
    #[IsGranted('commerces.read')]
    #[IsGranted('finance.read')]
    #[IsGranted('factures.read')]
    public function summary(int $id, Connection $db, EntityManagerInterface $em, #[CurrentUser] User $user): JsonResponse
    {
        $business = $em->find(Business::class, $id);
        if (!$business) return $this->json(['error' => 'Commerce introuvable.'], 404);

        // Audit minimal sans stocker les données de la consultation.
        $db->insert('business_financial_access_audit', [
            'business_id' => $id,
            'actor_user_id' => $user->getId(),
        ]);

        $summary = $db->fetchAssociative(<<<'SQL'
WITH r AS (
  SELECT i.id, i.status, i.total,
         COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid
  FROM invoices i WHERE i.business_id = :id
)
SELECT COUNT(*)::int AS invoice_count,
       COALESCE(SUM(total), 0)::numeric(14,2) AS total_billed,
       COALESCE(SUM(paid), 0)::numeric(14,2) AS total_paid,
       COALESCE(SUM(GREATEST(total-paid,0)) FILTER (WHERE status IN ('issued','paid')),0)::numeric(14,2) AS outstanding,
       COUNT(*) FILTER (WHERE status = 'draft')::int AS draft_count
FROM r
SQL, ['id' => $id]);
        $rows = $db->fetchAllAssociative(<<<'SQL'
SELECT i.number, i.period, i.status, i.total, i.currency, i.due_at,
       COALESCE(SUM(p.amount),0)::numeric(14,2) AS paid,
       CASE WHEN i.status IN ('issued','paid')
            THEN GREATEST(i.total-COALESCE(SUM(p.amount),0),0)::numeric(14,2)
            ELSE 0::numeric(14,2) END AS outstanding
FROM invoices i LEFT JOIN payments p ON p.invoice_id = i.id
WHERE i.business_id = :id
GROUP BY i.id, i.number, i.period, i.status, i.total, i.currency, i.due_at
ORDER BY i.period DESC, i.id DESC LIMIT 20
SQL, ['id' => $id]);
        // Les colonnes DATE peuvent arriver en objets/chaînes selon DBAL : uniformiser la sortie.
        $response = $this->json([
            'reference' => sprintf('GC-ATT-%06d', $business->getId()),
            'businessName' => $business->getName(),
            'currency' => 'XOF',
            'summary' => $summary,
            'invoices' => $rows,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
