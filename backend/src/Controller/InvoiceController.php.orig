<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Enum\PaymentMethod;
use App\Exception\PaymentRejectedException;
use App\Service\PaymentRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Consultation et encaissements manuels — à compléter par un journal d'audit avant production. */
#[Route('/api/invoices')]
#[IsGranted('ROLE_ADMIN')]
final class InvoiceController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'api_invoices_list', methods: ['GET'])]
    public function list(Request $request, PaymentRecorder $recorder): JsonResponse
    {
        $period = (string) $request->query->get('period', '');
        if ($period !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            return $this->json(['error' => 'La période doit être au format YYYY-MM.'], 422);
        }
        $page = max(1, min(100000, $request->query->getInt('page', 1)));
        $pageSize = 25;

        $qb = $this->em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->join('i.business', 'b')->addSelect('b');
        if ($period !== '') {
            $qb->andWhere('i.period = :period')
                ->setParameter('period', new \DateTimeImmutable($period . '-01'));
        }

        $total = (int) (clone $qb)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        /** @var list<Invoice> $invoices */
        $invoices = $qb->orderBy('i.id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()->getResult();

        return $this->json([
            'items' => array_map(fn (Invoice $i): array => $this->invoiceData($i, $recorder), $invoices),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    #[Route('/{id<\d+>}', name: 'api_invoices_show', methods: ['GET'])]
    public function show(int $id, PaymentRecorder $recorder): JsonResponse
    {
        $invoice = $this->em->find(Invoice::class, $id);
        if ($invoice === null) {
            return $this->json(['error' => 'Facture introuvable.'], 404);
        }
        return $this->json($this->invoiceData($invoice, $recorder, true));
    }

    #[Route('/{id<\d+>}/payments', name: 'api_invoices_payment_create', methods: ['POST'])]
    public function recordPayment(int $id, Request $request, PaymentRecorder $recorder): JsonResponse
    {
        $invoice = $this->em->find(Invoice::class, $id);
        if ($invoice === null) {
            return $this->json(['error' => 'Facture introuvable.'], 404);
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return $this->json(['error' => 'Content-Type application/json requis.'], 415);
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Corps JSON invalide.'], 400);
        }

        $amount = $data['amount'] ?? null;
        $method = is_string($data['method'] ?? null) ? PaymentMethod::tryFrom($data['method']) : null;
        $reference = $data['reference'] ?? null;
        if (!is_string($amount) || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return $this->json(['error' => 'Montant invalide : saisir un montant positif en texte.'], 422);
        }
        if ($method === null) {
            return $this->json(['error' => 'Mode de paiement non reconnu.'], 422);
        }
        // Une référence obligatoire facilite le contrôle des doubles saisies.
        if (!is_string($reference) || trim($reference) === '' || mb_strlen(trim($reference)) > 100) {
            return $this->json(['error' => 'Référence obligatoire (1 à 100 caractères).'], 422);
        }
        $reference = trim($reference);
        if ($this->em->getRepository(Payment::class)->findOneBy(['invoice' => $invoice, 'reference' => $reference]) !== null) {
            return $this->json(['error' => 'Cette référence est déjà utilisée pour cette facture. Vérifiez les encaissements.'], 409);
        }

        try {
            $payment = $recorder->record($invoice, $amount, $method, $reference);
        } catch (PaymentRejectedException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json([
            'payment' => $this->paymentData($payment),
            'invoice' => $this->invoiceData($invoice, $recorder, true),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function invoiceData(Invoice $invoice, PaymentRecorder $recorder, bool $details = false): array
    {
        $result = [
            'id' => $invoice->getId(),
            'number' => $invoice->getNumber(),
            'period' => $invoice->getPeriod()->format('Y-m-d'),
            'status' => $invoice->getStatus()->value,
            'issuedAt' => $invoice->getIssuedAt()?->format(DATE_ATOM),
            'dueAt' => $invoice->getDueAt()?->format('Y-m-d'),
            'business' => ['id' => $invoice->getBusiness()->getId(), 'name' => $invoice->getBusiness()->getName()],
            'total' => $invoice->getTotal(),
            'currency' => $invoice->getCurrency(),
            'paid' => $recorder->paidAmount($invoice),
            'balance' => $recorder->balance($invoice),
        ];
        if ($details) {
            $result['lines'] = [];
            foreach ($invoice->getLines() as $line) {
                $result['lines'][] = [
                    'label' => $line->getLabel(),
                    'quantity' => $line->getQuantity(),
                    'unitPrice' => $line->getUnitPrice(),
                    'amount' => $line->getAmount(),
                ];
            }
            $result['payments'] = [];
            foreach ($invoice->getPayments() as $payment) {
                $result['payments'][] = $this->paymentData($payment);
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function paymentData(Payment $p): array
    {
        return [
            'id' => $p->getId(),
            'amount' => $p->getAmount(),
            'method' => $p->getMethod()->value,
            'reference' => $p->getReference(),
            'receivedAt' => $p->getReceivedAt()->format(DATE_ATOM),
        ];
    }
}
