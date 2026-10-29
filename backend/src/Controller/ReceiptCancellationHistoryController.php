<?php
declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Consultation administrative, en lecture seule, des annulations de reçus. */
#[Route('/api/admin/receipts')]
#[IsGranted('ROLE_ADMIN')]
final class ReceiptCancellationHistoryController extends AbstractController
{
    #[Route('/cancellations', name: 'api_admin_receipt_cancellations', methods: ['GET'])]
    public function list(Request $request, Connection $db): JsonResponse
    {
        $from = trim((string) $request->query->get('from', ''));
        $to = trim((string) $request->query->get('to', ''));
        $business = trim((string) $request->query->get('business', ''));
        $receipt = trim((string) $request->query->get('receipt', ''));
        $actor = trim((string) $request->query->get('actor', ''));
        $page = max(1, min(100000, $request->query->getInt('page', 1)));
        $pageSize = 20;

        if (($from !== '' && !self::isDate($from)) || ($to !== '' && !self::isDate($to))) {
            return self::reply(['error' => 'Les dates doivent être au format YYYY-MM-DD.'], 422);
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            return self::reply(['error' => 'La date de début doit précéder la date de fin.'], 422);
        }
        foreach (['commerce' => $business, 'reçu' => $receipt, 'administrateur' => $actor] as $label => $value) {
            if (mb_strlen($value) > 120) {
                return self::reply(['error' => sprintf('Filtre %s trop long (120 caractères maximum).', $label)], 422);
            }
        }

        // Toutes les valeurs externes sont liées par paramètres ; aucune modification SQL.
        $query = $db->createQueryBuilder()
            ->from('receipts', 'r')
            ->where('r.status = :status')
            ->setParameter('status', 'cancelled');

        if ($from !== '') {
            $query->andWhere('r.cancelled_at >= :from')
                ->setParameter('from', $from . ' 00:00:00');
        }
        if ($to !== '') {
            $nextDay = (new \DateTimeImmutable($to . ' 00:00:00', new \DateTimeZone('UTC')))
                ->modify('+1 day')->format('Y-m-d H:i:s');
            $query->andWhere('r.cancelled_at < :toExclusive')
                ->setParameter('toExclusive', $nextDay);
        }
        if ($business !== '') {
            $query->andWhere('r.business_name ILIKE :business')
                ->setParameter('business', '%' . $business . '%');
        }
        if ($receipt !== '') {
            $query->andWhere('r.number ILIKE :receipt')
                ->setParameter('receipt', '%' . $receipt . '%');
        }
        if ($actor !== '') {
            $query->andWhere('r.cancelled_by_email ILIKE :actor')
                ->setParameter('actor', '%' . $actor . '%');
        }

        $total = (int) (clone $query)->select('COUNT(r.id)')->executeQuery()->fetchOne();

        /** @var list<array<string, mixed>> $rows */
        $rows = $query
            ->select(
                'r.id', 'r.payment_id', 'r.number', 'r.invoice_number',
                'r.business_name', 'r.amount', 'r.currency', 'r.status',
                'r.cancellation_reason', 'r.cancelled_at', 'r.cancelled_by_email'
            )
            ->orderBy('r.cancelled_at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->executeQuery()->fetchAllAssociative();

        $items = array_map(static function (array $row): array {
            // TIMESTAMP WITHOUT TIME ZONE : le service d'annulation stocke l'heure UTC.
            $cancelledAt = new \DateTimeImmutable((string) $row['cancelled_at'], new \DateTimeZone('UTC'));
            return [
                'id' => (int) $row['id'],
                'paymentId' => (int) $row['payment_id'],
                'number' => (string) $row['number'],
                'invoiceNumber' => (string) $row['invoice_number'],
                'businessName' => (string) $row['business_name'],
                'amount' => (string) $row['amount'],
                'currency' => (string) $row['currency'],
                'status' => (string) $row['status'],
                'reason' => (string) $row['cancellation_reason'],
                'cancelledAt' => $cancelledAt->format('Y-m-d\TH:i:s\Z'),
                'cancelledBy' => (string) $row['cancelled_by_email'],
            ];
        }, $rows);

        return self::reply([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    private static function isDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $data */
    private static function reply(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}
