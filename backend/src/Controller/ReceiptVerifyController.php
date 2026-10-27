<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Receipt;
use App\Service\ReceiptIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Lecture seule, accessible sans session : tokens cryptographiquement imprévisibles. */
final class ReceiptVerifyController extends AbstractController
{
    #[Route('/api/receipts/verify/{token}.json', name: 'api_receipts_verify_json', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function verifyJson(string $token, EntityManagerInterface $em, ReceiptIssuer $issuer): JsonResponse
    {
        $receipt = $em->getRepository(Receipt::class)->findOneBy(['verificationToken' => $token]);
        if (!$receipt instanceof Receipt) {
            return $this->nocache(new JsonResponse(['valid' => false, 'status' => 'unknown'], 404));
        }
        $authentic = $issuer->isAuthentic($receipt);
        $valid = $authentic && $receipt->getStatus() === 'issued';

        $data = ['valid' => $valid, 'status' => !$authentic ? 'altered' : $receipt->getStatus()];
        if ($authentic) {
            $data += [
                'receipt_number' => $receipt->getNumber(),
                'invoice_number' => $receipt->getInvoiceNumber(),
                'business_name' => $receipt->getBusinessName(),
                'amount' => $receipt->getAmount(),
                'currency' => $receipt->getCurrency(),
                'payment_method' => $receipt->getPaymentMethod(),
                'received_at' => $receipt->getReceivedAt()->format(DATE_ATOM),
            ];
        }
        return $this->nocache(new JsonResponse($data));
    }

    #[Route('/api/receipts/verify/{token}', name: 'api_receipts_verify_page', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function page(string $token, EntityManagerInterface $em, ReceiptIssuer $issuer): Response
    {
        $receipt = $em->getRepository(Receipt::class)->findOneBy(['verificationToken' => $token]);
        if (!$receipt instanceof Receipt) {
            return $this->nocache(new Response($this->layout('Reçu inconnu', 'Le reçu demandé est introuvable.', ''), 404));
        }
        $authentic = $issuer->isAuthentic($receipt);
        $valid = $authentic && $receipt->getStatus() === 'issued';
        $heading = $valid ? 'Reçu valide' : 'Reçu non valide';
        $message = $valid ? 'Ce reçu correspond à un encaissement enregistré.' :
            ($authentic ? 'Ce reçu a été annulé.' : 'Incohérence détectée dans les données du reçu.');
        $details = '';
        if ($authentic) {
            $details = sprintf(
                '<dl><dt>Numéro</dt><dd>%s</dd><dt>Facture</dt><dd>%s</dd><dt>Commerce</dt><dd>%s</dd><dt>Montant encaissé</dt><dd>%s %s</dd><dt>Date</dt><dd>%s (UTC)</dd></dl>',
                $this->escape($receipt->getNumber()),
                $this->escape($receipt->getInvoiceNumber()),
                $this->escape($receipt->getBusinessName()),
                $this->escape(number_format((float) $receipt->getAmount(), 0, ',', ' ')),
                $this->escape($receipt->getCurrency()),
                $this->escape($receipt->getReceivedAt()->format('d/m/Y H:i')),
            );
        }
        return $this->nocache(new Response($this->layout($heading, $message, $details), 200));
    }

    private function layout(string $title, string $message, string $details): string
    {
        $title = $this->escape($title);
        $message = $this->escape($message);
        return <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} — GC-Attinguié</title>
<style>body{background:#f5f7fb;color:#14243e;font:16px system-ui,sans-serif;margin:0;padding:2rem 1rem}
main{max-width:580px;margin:2rem auto;padding:2rem;background:white;border-radius:12px;box-shadow:0 5px 25px #0001}
h1{font-size:1.6rem}dt{font-size:.85rem;color:#556}dd{font-weight:600;margin:0 0 .9rem}dl{margin-top:2rem}</style>
</head><body><main><small>Gestion municipale des commerces — Attinguié</small><h1>{$title}</h1><p>{$message}</p>{$details}
<p><small>Vérifiez que les informations affichées correspondent à celles du document présenté.</small></p></main></body></html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function nocache(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}
