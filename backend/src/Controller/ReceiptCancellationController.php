<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Receipt;
use App\Entity\User;
use App\Service\ReceiptCancellationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Interface administrative temporaire + API pour la future interface Vue.js. */
#[Route('/api/receipts')]
#[IsGranted('ROLE_ADMIN')]
final class ReceiptCancellationController extends AbstractController
{
    private const CSRF_PREFIX = 'cancel_receipt_';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $tokens,
    ) {
    }

    #[Route('/{id<\d+>}/cancel', name: 'api_receipts_cancel_form', methods: ['GET'])]
    public function form(int $id, Request $request): Response
    {
        $receipt = $this->em->find(Receipt::class, $id);
        if (!$receipt instanceof Receipt) {
            throw $this->createNotFoundException('Reçu introuvable.');
        }
        $isJson = str_contains($request->headers->get('Accept', ''), 'application/json');
        $csrf = $this->tokens->getToken(self::CSRF_PREFIX . $id)->getValue();
        if ($isJson) {
            return $this->noCache(new JsonResponse([
                'receipt_id' => $id,
                'receipt_number' => $receipt->getNumber(),
                'status' => $receipt->getStatus(),
                'csrf_token' => $csrf,
            ]));
        }

        $number = self::escape($receipt->getNumber());
        $amount = self::escape($receipt->getAmount());
        $currency = self::escape($receipt->getCurrency());
        $status = self::escape($receipt->getStatus());
        $token = self::escape($csrf);
        $disabled = $receipt->getStatus() === 'issued' ? '' : 'disabled';
        $html = <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Annulation de reçu — GC-Attinguié</title>
<style>body{font:16px system-ui,sans-serif;background:#f4f5f8;color:#17233b}main{background:white;max-width:650px;margin:7vh auto;padding:30px;border-radius:14px}
textarea{width:100%;box-sizing:border-box;min-height:120px;padding:10px}button{background:#92372b;color:white;border:0;padding:12px 18px;cursor:pointer}
small{color:#526070}label{display:block;margin:1em 0 .5em}</style></head><body><main>
<h1>Annuler un reçu</h1><p>Reçu : <strong>{$number}</strong></p><p>Montant : {$amount} {$currency} — état : <strong>{$status}</strong></p>
<p><strong>Attention :</strong> cette annulation révoque le justificatif et le QR code, mais n'annule ni le paiement ni la facture.</p>
<form method="post" action="/api/receipts/{$id}/cancel">
<input type="hidden" name="csrf_token" value="{$token}">
<label for="reason">Motif administratif (10 à 500 caractères)</label>
<textarea id="reason" name="reason" minlength="10" maxlength="500" required></textarea>
<p><button type="submit" {$disabled}>Confirmer l'annulation irréversible</button></p>
</form><small>Fonction réservée au rôle ROLE_ADMIN. Conservez une preuve du motif dans l'historique.</small>
</main></body></html>
HTML;
        return $this->noCache(new Response($html));
    }

    #[Route('/{id<\d+>}/cancel', name: 'api_receipts_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, ReceiptCancellationService $cancellations): Response
    {
        $isJson = $request->getContentTypeFormat() === 'json';
        if ($isJson) {
            try {
                $data = $request->toArray();
            } catch (\Throwable) {
                return $this->result($isJson, 400, ['error' => 'JSON invalide.']);
            }
        } elseif ($request->getContentTypeFormat() === 'form') {
            $data = $request->request->all();
        } else {
            return $this->result($isJson, 415, ['error' => 'Content-Type application/json ou formulaire requis.']);
        }

        $csrf = $data['csrf_token'] ?? null;
        if (!is_string($csrf) || !$this->tokens->isTokenValid(new CsrfToken(self::CSRF_PREFIX . $id, $csrf))) {
            return $this->result($isJson, 403, ['error' => 'Jeton CSRF invalide. Rechargez le formulaire.']);
        }
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            return $this->result($isJson, 403, ['error' => 'Authentification requise.']);
        }
        $reason = $data['reason'] ?? null;
        if (!is_string($reason)) {
            return $this->result($isJson, 422, ['error' => 'Motif obligatoire.']);
        }
        try {
            $result = $cancellations->cancel($id, $actor, $reason);
        } catch (\InvalidArgumentException $e) {
            return $this->result($isJson, 422, ['error' => $e->getMessage()]);
        } catch (\OutOfBoundsException $e) {
            return $this->result($isJson, 404, ['error' => $e->getMessage()]);
        } catch (\DomainException $e) {
            return $this->result($isJson, 409, ['error' => $e->getMessage()]);
        }
        return $this->result($isJson, 200, $result);
    }

    /** @param array<string,mixed> $data */
    private function result(bool $json, int $status, array $data): Response
    {
        if ($json) {
            return $this->noCache(new JsonResponse($data, $status));
        }
        $message = self::escape($data['error'] ?? 'Le reçu a été annulé. Son QR code indique désormais « Reçu annulé ».');
        $heading = $status === 200 ? 'Annulation enregistrée' : 'Annulation impossible';
        return $this->noCache(new Response('<!doctype html><html lang="fr"><meta charset="utf-8"><title>GC-Attinguié</title><main><h1>'. $heading .'</h1><p>'. $message .'</p></main></html>', $status));
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function noCache(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}
