<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\ActivityTariff;
use App\Entity\Business;
use App\Entity\BusinessBillingProfile;
use App\Entity\DailyActivityRecord;
use App\Entity\MerchantBusinessAccess;
use App\Entity\User;
use App\Enum\BusinessStatus;
use App\Service\BillingProfileResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/billing/businesses')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBillingController extends AbstractController
{
    private const CSRF_ID = 'gc_billing_admin_v1';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BillingProfileResolver $resolver,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('/{id<\d+>}', name: 'gc_billing_profile_get', methods: ['GET'])]
    public function view(int $id): JsonResponse
    {
        $business = $this->em->find(Business::class, $id);
        if (!$business) return $this->json(['error' => 'Commerce introuvable.'], 404);
        $profiles = $this->em->getRepository(BusinessBillingProfile::class)->findBy(['business' => $business], ['effectiveFrom' => 'DESC']);
        $entries = $this->em->getRepository(DailyActivityRecord::class)->findBy(['business' => $business], ['activityDate' => 'DESC'], 30);
        $accesses = $this->em->getRepository(MerchantBusinessAccess::class)->findBy(['business' => $business]);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Africa/Abidjan'));
        $tariffs = $this->em->getRepository(ActivityTariff::class)->findBy(['activityType' => $business->getActivityType()]);
        return $this->json([
            'business' => ['id' => $id, 'name' => $business->getName(), 'status' => $business->getStatus()->value],
            'currentFrequency' => $this->resolver->frequencyAt($business, $today),
            'profiles' => array_map(static fn (BusinessBillingProfile $p) => [
                'id' => $p->getId(), 'frequency' => $p->getFrequency(),
                'effectiveFrom' => $p->getEffectiveFrom()->format('Y-m-d'), 'reason' => $p->getReason(),
            ], $profiles),
            'dailyRecords' => array_map(fn (DailyActivityRecord $r) => $this->recordData($r), $entries),
            'tariffs' => array_map(static fn (ActivityTariff $t) => [
                'frequency' => $t->getPeriod(), 'amount' => $t->getAmount(), 'active' => $t->isActive(),
            ], $tariffs),
            'merchantUsers' => array_values(array_map(static fn (MerchantBusinessAccess $a) => [
                'email' => $a->getUser()->getEmail(), 'userId' => $a->getUser()->getId(),
                'active' => $a->getRevokedAt() === null,
            ], $accesses)),
            'csrf' => (string) $this->csrf->getToken(self::CSRF_ID)->getValue(),
            'note' => 'Les constats journaliers ne constituent pas encore des factures ni des reçus de paiement.',
        ]);
    }

    #[Route('/{id<\d+>}/profiles', name: 'gc_billing_profile_create', methods: ['POST'])]
    public function addProfile(int $id, Request $request): JsonResponse
    {
        $body = $this->body($request);
        if ($body instanceof JsonResponse) return $body;
        $business = $this->em->find(Business::class, $id);
        if (!$business) return $this->json(['error' => 'Commerce introuvable.'], 404);
        $frequency = $body['frequency'] ?? null;
        $reason = trim((string) ($body['reason'] ?? ''));
        $date = $this->date($body['effectiveFrom'] ?? null);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Africa/Abidjan'));
        $firstNextMonth = $today->modify('first day of next month');
        if (!is_string($frequency) || !in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)
            || $date === null || $date->format('d') !== '01' || $date < $firstNextMonth
            || $date > $firstNextMonth->modify('+24 months') || mb_strlen($reason) < 6 || mb_strlen($reason) > 255) {
            return $this->json(['error' => 'Périodicité, date d’effet (1er jour d’un mois futur) ou motif (6-255 caractères) invalide.'], 422);
        }
        if ($this->em->getRepository(BusinessBillingProfile::class)->findOneBy(['business' => $business, 'effectiveFrom' => $date])) {
            return $this->json(['error' => 'Un profil existe déjà à cette date : aucune ligne historique ne sera écrasée.'], 409);
        }
        // Pas d'exigence tarifaire pour l'enregistrement du profil : le moteur s'abstient si tarif nul.
        $profile = new BusinessBillingProfile($business, $frequency, $date, $reason, $this->actor());
        $this->em->persist($profile);
        $this->em->flush();
        return $this->json(['id' => $profile->getId(), 'effectiveFrom' => $date->format('Y-m-d'), 'frequency' => $frequency], 201);
    }

    #[Route('/{id<\d+>}/daily', name: 'gc_billing_daily_create', methods: ['POST'])]
    public function recordDaily(int $id, Request $request): JsonResponse
    {
        $body = $this->body($request);
        if ($body instanceof JsonResponse) return $body;
        $business = $this->em->find(Business::class, $id);
        if (!$business) return $this->json(['error' => 'Commerce introuvable.'], 404);
        if ($business->getStatus() !== BusinessStatus::Active) {
            return $this->json(['error' => 'Commerce non actif : constat refusé.'], 422);
        }
        $date = $this->date($body['activityDate'] ?? null);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Africa/Abidjan'));
        $note = trim((string) ($body['note'] ?? ''));
        if ($date === null || $date->format('Y-m-d') !== $today->format('Y-m-d') || mb_strlen($note) > 250) {
            return $this->json(['error' => 'Constat autorisé uniquement pour la date du jour (Africa/Abidjan), note maximale : 250 caractères.'], 422);
        }
        if ($this->resolver->frequencyAt($business, $date) !== 'daily') {
            return $this->json(['error' => 'Ce commerce n’est pas en tarification journalière à cette date.'], 422);
        }
        if ($this->em->getRepository(DailyActivityRecord::class)->findOneBy(['business' => $business, 'activityDate' => $date])) {
            return $this->json(['error' => 'Un constat existe déjà pour ce jour, même s’il a été annulé.'], 409);
        }
        $tariff = $this->em->getRepository(ActivityTariff::class)->findOneBy([
            'activityType' => $business->getActivityType(), 'period' => 'daily', 'active' => true,
        ]);
        if (!$tariff || bccomp($tariff->getAmount(), '0', 2) <= 0) {
            return $this->json(['error' => 'Aucun tarif journalier actif positif pour cette activité.'], 422);
        }
        $record = new DailyActivityRecord($business, $date, $tariff->getAmount(), $this->actor(), $note ?: null);
        $this->em->persist($record);
        $this->em->flush();
        return $this->json($this->recordData($record), 201);
    }

    #[Route('/{id<\d+>}/daily/{recordId<\d+>}/void', name: 'gc_billing_daily_void', methods: ['POST'])]
    public function voidDaily(int $id, int $recordId, Request $request): JsonResponse
    {
        $body = $this->body($request);
        if ($body instanceof JsonResponse) return $body;
        $record = $this->em->find(DailyActivityRecord::class, $recordId);
        if (!$record || $record->getBusiness()->getId() !== $id) return $this->json(['error' => 'Constat introuvable.'], 404);
        $reason = trim((string) ($body['reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 255) return $this->json(['error' => 'Motif de 10 à 255 caractères requis.'], 422);
        if ($record->getStatus() !== 'confirmed') return $this->json(['error' => 'Constat déjà annulé.'], 409);
        $record->void($this->actor(), $reason);
        $this->em->flush();
        return $this->json($this->recordData($record));
    }

    #[Route('/{id<\d+>}/merchant-users', name: 'gc_billing_merchant_link', methods: ['POST'])]
    public function addMerchant(int $id, Request $request): JsonResponse
    {
        $body = $this->body($request);
        if ($body instanceof JsonResponse) return $body;
        $business = $this->em->find(Business::class, $id);
        if (!$business) return $this->json(['error' => 'Commerce introuvable.'], 404);
        $email = trim((string) ($body['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->json(['error' => 'Adresse e-mail invalide.'], 422);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user || !$user->isActive()) return $this->json(['error' => 'Compte actif introuvable.'], 404);
        $repo = $this->em->getRepository(MerchantBusinessAccess::class);
        $access = $repo->findOneBy(['user' => $user, 'business' => $business]);
        if ($access && $access->getRevokedAt() === null) return $this->json(['error' => 'Ce compte est déjà rattaché.'], 409);
        if ($access) $access->grant($this->actor());
        else $this->em->persist(new MerchantBusinessAccess($user, $business, $this->actor()));
        $this->em->flush();
        return $this->json(['email' => $email, 'active' => true], 201);
    }

    #[Route('/{id<\d+>}/merchant-users/{userId<\d+>}/revoke', name: 'gc_billing_merchant_unlink', methods: ['POST'])]
    public function revokeMerchant(int $id, int $userId, Request $request): JsonResponse
    {
        $body = $this->body($request);
        if ($body instanceof JsonResponse) return $body;
        $access = $this->em->getRepository(MerchantBusinessAccess::class)->createQueryBuilder('a')
            ->where('IDENTITY(a.business) = :bid AND IDENTITY(a.user) = :uid')
            ->setParameter('bid', $id)->setParameter('uid', $userId)->getQuery()->getOneOrNullResult();
        if (!$access) return $this->json(['error' => 'Rattachement introuvable.'], 404);
        $access->revoke();
        $this->em->flush();
        return $this->json(['revoked' => true]);
    }

    private function actor(): ?User { $user = $this->getUser(); return $user instanceof User ? $user : null; }

    private function recordData(DailyActivityRecord $record): array
    {
        return [
            'id' => $record->getId(), 'activityDate' => $record->getActivityDate()->format('Y-m-d'),
            'amount' => $record->getUnitAmount(), 'currency' => $record->getCurrency(),
            'status' => $record->getStatus(), 'note' => $record->getNote(),
            'observedAt' => $record->getObservedAt()->format(DATE_ATOM),
            'voidReason' => $record->getVoidReason(),
        ];
    }

    private function date(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('Africa/Abidjan'));
        return $date && $date->format('Y-m-d') === $raw ? $date : null;
    }

    /** @return array<string,mixed>|JsonResponse */
    private function body(Request $request): array|JsonResponse
    {
        if ($request->getContentTypeFormat() !== 'json') return $this->json(['error' => 'JSON requis.'], 415);
        try { $body = $request->toArray(); }
        catch (\Throwable) { return $this->json(['error' => 'Objet JSON invalide.'], 400); }
        if (!is_array($body)) return $this->json(['error' => 'Objet JSON requis.'], 422);
        $token = $body['csrf'] ?? null;
        if (!is_string($token) || !$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $token))) {
            return $this->json(['error' => 'Jeton de sécurité expiré : rechargez la page.'], 403);
        }
        return $body;
    }
}
