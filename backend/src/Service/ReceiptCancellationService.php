<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Receipt;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** Annulation irréversible d'un justificatif ; aucun mouvement financier n'est modifié. */
final class ReceiptCancellationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReceiptIssuer $issuer,
    ) {
    }

    /** @return array{number:string,status:string,reason:string,cancelled_at:string,cancelled_by:string} */
    public function cancel(int $receiptId, User $actor, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
            throw new \InvalidArgumentException('Motif obligatoire de 10 à 500 caractères, sans caractères de contrôle.');
        }
        $userId = $actor->getId();
        if ($userId === null || !in_array('ROLE_ADMIN', $actor->getRoles(), true)) {
            throw new \LogicException('Administrateur authentifié requis.');
        }

        // Le verrou PostgreSQL sérialise les demandes concurrentes pour un même reçu.
        return $this->em->getConnection()->transactional(function (Connection $conn) use ($receiptId, $userId, $actor, $reason): array {
            $row = $conn->fetchAssociative(
                'SELECT id, status FROM receipts WHERE id = :id FOR UPDATE',
                ['id' => $receiptId],
            );
            if ($row === false) {
                throw new \OutOfBoundsException('Reçu introuvable.');
            }
            if ($row['status'] !== 'issued') {
                throw new \DomainException('Ce reçu est déjà annulé ou ne peut pas être annulé.');
            }

            $receipt = $this->em->find(Receipt::class, $receiptId);
            if (!$receipt instanceof Receipt) {
                throw new \OutOfBoundsException('Reçu introuvable.');
            }
            $this->em->refresh($receipt);
            if (!$this->issuer->isAuthentic($receipt)) {
                throw new \DomainException('Signature du reçu invalide : annulation refusée.');
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $affected = $conn->executeStatement(
                <<<'SQL'
UPDATE receipts
SET status = 'cancelled', cancelled_at = :at,
    cancelled_by_user_id = :user_id, cancellation_reason = :reason, cancelled_by_email = :email
WHERE id = :id AND status = 'issued'
SQL,
                [
                    'at' => $now->format('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'reason' => $reason,
                    'email' => $actor->getUserIdentifier(),
                    'id' => $receiptId,
                ],
            );
            if ($affected !== 1) {
                throw new \DomainException('Ce reçu a déjà été annulé.');
            }
            return [
                'number' => $receipt->getNumber(),
                'status' => 'cancelled',
                'reason' => $reason,
                'cancelled_at' => $now->format(DATE_ATOM),
                'cancelled_by' => $actor->getUserIdentifier(),
            ];
        });
    }
}
