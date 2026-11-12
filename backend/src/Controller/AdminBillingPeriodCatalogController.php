<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\BillingPeriodCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Référentiel en lecture seule : aucune bascule de moteur de facturation. */
#[Route('/api/admin/catalog/periods')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBillingPeriodCatalogController extends AbstractController
{
    #[Route('', name: 'gc_admin_billing_periods_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $response = $this->json(['items' => BillingPeriodCatalog::all()]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
