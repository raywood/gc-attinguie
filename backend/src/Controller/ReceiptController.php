<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Payment;
use App\Service\ReceiptPdfGenerator;
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
}
