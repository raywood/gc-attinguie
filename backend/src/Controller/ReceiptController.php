<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\Receipt;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\ReceiptPdfGenerator;
use App\Service\CancelledReceiptPdfGenerator;
use App\Service\ReceiptIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Téléchargement d'un reçu PDF par paiement, réservé à l'administration. */
#[Route('/api/payments')]
#[IsGranted('ROLE_ADMIN')]
final class ReceiptController extends AbstractController
{
    #[Route('/{id<\d+>}/receipt', name: 'api_payments_receipt_pdf', methods: ['GET'])]
    public function receipt(int $id, EntityManagerInterface $em, ReceiptPdfGenerator $generator): Response
    {
        $payment = $em->find(Payment::class, $id);
        if ($payment === null) {
            throw $this->createNotFoundException('Paiement introuvable.');
        }

        $stored = $em->getRepository(Receipt::class)->findOneBy(['payment' => $payment]);
        if ($stored instanceof Receipt && $stored->getStatus() === 'cancelled') {
            return new JsonResponse(['error' => 'Reçu annulé : nouveau téléchargement interdit. Le paiement demeure enregistré.'], 409);
        }

        $filename = $generator->receiptNumber($payment) . '.pdf';
        $response = new Response($generator->render($payment));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
        ));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Lecture seule : copie administrative d'un reçu annulé à partir du snapshot
     * signé et de l'historique de révocation. L'URL de téléchargement normal
     * reste bloquée en 409 pour éviter une réémission ambiguë.
     */
    #[Route('/{id<\d+>}/receipt/administrative.pdf', name: 'api_payments_receipt_admin_pdf', methods: ['GET'])]
    public function administrativePdf(
        int $id,
        EntityManagerInterface $em,
        CancelledReceiptPdfGenerator $generator,
        ReceiptIssuer $issuer,
    ): Response {
        $payment = $em->find(Payment::class, $id);
        if ($payment === null) {
            throw $this->createNotFoundException('Paiement introuvable.');
        }
        $receipt = $em->getRepository(Receipt::class)->findOneBy(['payment' => $payment]);
        if (!$receipt instanceof Receipt) {
            throw $this->createNotFoundException('Reçu introuvable.');
        }
        if ($receipt->getStatus() !== 'cancelled') {
            return new JsonResponse(['error' => 'La consultation administrative est réservée aux reçus annulés.'], 409);
        }
        if (!$issuer->isAuthentic($receipt)) {
            return new JsonResponse(['error' => 'Signature du reçu invalide : consultation refusée.'], 409);
        }
        if ($receipt->getCancelledAt() === null || !trim((string) $receipt->getCancellationReason())
            || !trim((string) $receipt->getCancelledByEmail())) {
            return new JsonResponse(['error' => 'Historique d’annulation incomplet.'], 409);
        }

        $response = new Response($generator->render($receipt));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $receipt->getNumber() . '-ANNULE-consultation.pdf',
        ));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

}
