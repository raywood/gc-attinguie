<?php
declare(strict_types=1);

namespace App\Controller;

use App\Dto\BusinessRowInput;
use App\Entity\Business;
use App\Entity\BusinessManager;
use App\Entity\Manager;
use App\Enum\BusinessStatus;
use App\Service\BusinessRegistrar;
use App\Service\BusinessUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/businesses')]
final class BusinessController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const MAX_ROWS = 200;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'api_businesses_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $q = trim((string) $request->query->get('q', ''));

        $qb = $this->em->createQueryBuilder()
            ->select('b', 't')
            ->from(Business::class, 'b')
            ->join('b.activityType', 't');

        if ($q !== '') {
            $qb->andWhere('LOWER(b.name) LIKE :q OR LOWER(b.district) LIKE :q')
                ->setParameter('q', '%' . addcslashes(mb_strtolower($q), '%_\\') . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(b.id)')->getQuery()->getSingleScalarResult();

        /** @var list<Business> $items */
        $items = $qb->orderBy('b.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        $managers = $this->primaryManagers(array_map(static fn (Business $b) => $b->getId(), $items));

        return $this->json([
            'items' => array_map(static function (Business $b) use ($managers): array {
                $m = $managers[$b->getId()] ?? null;

                return [
                    'id' => $b->getId(),
                    'name' => $b->getName(),
                    'activityType' => ['id' => $b->getActivityType()->getId(), 'label' => $b->getActivityType()->getLabel()],
                    'address' => $b->getAddress(),
                    'district' => $b->getDistrict(),
                    'status' => $b->getStatus()->value,
                    'manager' => $m ? ['fullName' => $m->getFullName(), 'phone' => $m->getPhone()] : null,
                ];
            }, $items),
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
        ]);
    }

    /** Enregistre plusieurs commerces d'un coup (tout ou rien). */
    #[Route('', name: 'api_businesses_create', methods: ['POST'])]
    public function create(Request $request, ValidatorInterface $validator, BusinessRegistrar $registrar): JsonResponse
    {
        if ($request->getContentTypeFormat() !== 'json') {
            return new JsonResponse(['error' => 'Content-Type application/json requis.'], 415);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'JSON invalide.'], 400);
        }

        $rawRows = $data['rows'] ?? null;
        if (!is_array($rawRows) || !array_is_list($rawRows) || $rawRows === []) {
            return new JsonResponse(['error' => 'Aucun commerce à enregistrer.'], 400);
        }
        if (count($rawRows) > self::MAX_ROWS) {
            return new JsonResponse(['error' => sprintf('Maximum %d commerces par envoi.', self::MAX_ROWS)], 422);
        }

        $types = $registrar->activityTypesById();
        $inputs = [];
        $errors = [];

        foreach ($rawRows as $i => $raw) {
            $input = BusinessRowInput::fromArray(is_array($raw) ? $raw : []);
            $inputs[] = $input;

            foreach ($validator->validate($input) as $violation) {
                $errors["rows.$i." . $violation->getPropertyPath()] ??= $violation->getMessage();
            }
            if ($input->activityTypeId !== null && !isset($types[$input->activityTypeId])) {
                $errors["rows.$i.activityTypeId"] ??= 'Type d’activité inconnu.';
            }
        }

        if ($errors !== []) {
            return new JsonResponse(['errors' => $errors], 422);
        }

        $created = $registrar->register($inputs);

        return new JsonResponse([
            'created' => count($created),
            'ids' => array_map(static fn (Business $b) => $b->getId(), $created),
        ], 201);
    }

    /** Consulte un commerce pour pre-remplir le formulaire de modification. */
    #[Route('/{id<\d+>}', name: 'api_businesses_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $business = $this->em->find(Business::class, $id);
        if ($business === null) {
            return new JsonResponse(['error' => 'Commerce introuvable.'], 404);
        }

        $manager = $this->primaryManagers([$id])[$id] ?? null;
        return $this->json($this->editPayload($business, $manager));
    }

    /** Mise a jour d'un commerce par un administrateur. */
    #[Route('/{id<\d+>}', name: 'api_businesses_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(
        int $id,
        Request $request,
        ValidatorInterface $validator,
        BusinessUpdater $updater,
    ): JsonResponse {
        $business = $this->em->find(Business::class, $id);
        if ($business === null) {
            return new JsonResponse(['error' => 'Commerce introuvable.'], 404);
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return new JsonResponse(['error' => 'Content-Type application/json requis.'], 415);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'JSON invalide.'], 400);
        }

        $input = BusinessRowInput::fromArray($data);
        $errors = [];
        foreach ($validator->validate($input) as $violation) {
            $errors[$violation->getPropertyPath()] ??= $violation->getMessage();
        }

        $status = is_string($data['status'] ?? null)
            ? BusinessStatus::tryFrom($data['status'])
            : null;
        if ($status === null) {
            $errors['status'] = 'Statut invalide.';
        }
        if ($input->activityTypeId !== null
            && $this->em->find(\App\Entity\ActivityType::class, $input->activityTypeId) === null) {
            $errors['activityTypeId'] = 'Type d’activité inconnu.';
        }
        if ($errors !== []) {
            return new JsonResponse(['errors' => $errors], 422);
        }

        $updater->update($business, $input, $status);
        $manager = $this->primaryManagers([$id])[$id] ?? null;

        return $this->json($this->editPayload($business, $manager));
    }

    /** @return array<string, mixed> */
    private function editPayload(Business $business, ?Manager $manager): array
    {
        return [
            'id' => $business->getId(),
            'name' => $business->getName(),
            'activityTypeId' => $business->getActivityType()->getId(),
            'address' => $business->getAddress(),
            'district' => $business->getDistrict(),
            'status' => $business->getStatus()->value,
            'managerLastName' => $manager?->getLastName(),
            'managerFirstName' => $manager?->getFirstName(),
            'managerPhone' => $manager?->getPhone(),
        ];
    }

    /**
     * @param list<int> $businessIds
     * @return array<int, \App\Entity\Manager>
     */
    private function primaryManagers(array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }

        $links = $this->em->createQuery(
            'SELECT bm, m FROM ' . BusinessManager::class . ' bm JOIN bm.manager m
             WHERE IDENTITY(bm.business) IN (:ids) AND bm.endedAt IS NULL AND bm.isPrimary = true'
        )->setParameter('ids', $businessIds)->getResult();

        $map = [];
        foreach ($links as $link) {
            $map[$link->getBusiness()->getId()] = $link->getManager();
        }

        return $map;
    }
}
