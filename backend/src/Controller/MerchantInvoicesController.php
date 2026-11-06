<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\MerchantBusinessAccess;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Service\MerchantInvoicePdfService;
use App\Service\PaymentRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/merchant')]
#[IsGranted('ROLE_USER')]
final class MerchantInvoicesController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/invoices', name: 'gc_merchant_invoice_list', methods: ['GET'])]
    public function list(PaymentRecorder $payments): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authentification requise.'], 401);
        $accesses = $this->em->getRepository(MerchantBusinessAccess::class)->findBy(['user' => $user, 'revokedAt' => null]);
        $businesses = array_map(static fn (MerchantBusinessAccess $a) => [
            'id' => $a->getBusiness()->getId(), 'name' => $a->getBusiness()->getName(),
        ], $accesses);
        if ($businesses === []) return $this->json(['businesses' => [], 'items' => []]);
        $ids = array_column($businesses, 'id');
        $invoices = $this->em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('IDENTITY(i.business) IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('i.status IN (:states)')->setParameter('states', ['issued', 'paid'])
            ->orderBy('i.period', 'DESC')->addOrderBy('i.id','DESC')
            ->setMaxResults(200)->getQuery()->getResult();
        return $this->json([
            'businesses' => $businesses,
            'items' => array_map(static fn (Invoice $i) => [
                'id' => $i->getId(), 'number' => $i->getNumber(),
                'businessId' => $i->getBusiness()->getId(), 'businessName' => $i->getBusiness()->getName(),
                'period' => $i->getPeriod()->format('Y-m'),
                'status' => $i->getStatus()->value,
                'total' => $i->getTotal(), 'paid' => $payments->paidAmount($i),
                'balance' => $payments->balance($i), 'dueAt' => $i->getDueAt()?->format('Y-m-d'),
            ], $invoices),
        ]);
    }

    #[Route('/invoices/{id<\d+>}/pdf', name: 'gc_merchant_invoice_pdf', methods: ['GET'])]
    public function pdf(int $id, MerchantInvoicePdfService $pdf): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authentification requise.'], 401);
        $invoice = $this->em->find(Invoice::class, $id);
        if (!$invoice || !in_array($invoice->getStatus(), [InvoiceStatus::Issued, InvoiceStatus::Paid], true)) {
            return $this->json(['error' => 'Facture introuvable.'], 404);
        }
        $access = $this->em->getRepository(MerchantBusinessAccess::class)->findOneBy([
            'user' => $user, 'business' => $invoice->getBusiness(), 'revokedAt' => null,
        ]);
        if (!$access) return $this->json(['error' => 'Accès refusé.'], 403);
        $binary = $pdf->render($invoice);
        return new Response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="facture-%s.pdf"', preg_replace('/[^A-Za-z0-9_-]/', '-', $invoice->getNumber())),
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
