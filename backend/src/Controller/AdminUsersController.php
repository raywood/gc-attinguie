<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUsersController extends AbstractController
{
    private const ROLES = ['ROLE_AGENT', 'ROLE_REGISSEUR', 'ROLE_COMPTABLE', 'ROLE_ADMIN'];
    private const CSRF_ID = 'gc_admin_users_v1';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('', name: 'api_admin_users_list', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, min(10000, (int) $request->query->get('page', '1')));
        $search = trim((string) $request->query->get('search', ''));
        if (mb_strlen($search) > 100) {
            return $this->json(['error' => 'Recherche limitée à 100 caractères.'], 422);
        }
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE (u.email ILIKE :search OR p.first_name ILIKE :search OR p.last_name ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $conn = $this->em->getConnection();
        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id' . $where, $params);
        $pageSize = 20;
        $offset = ($page - 1) * $pageSize;
        $rows = $conn->fetchAllAssociative(
            'SELECT u.id, u.email, u.roles, u.is_active, u.created_at,
                    p.first_name, p.last_name, p.phone, p.job_title, p.invitation_expires_at, p.activated_at
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id' . $where .
            ' ORDER BY u.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
            $params,
        );
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'roles' => json_decode((string) $r['roles'], true) ?: [],
            'isActive' => in_array($r['is_active'], [true, 1, '1', 't', 'true'], true),
            'firstName' => $r['first_name'],
            'lastName' => $r['last_name'],
            'phone' => $r['phone'],
            'jobTitle' => $r['job_title'],
            'invitationExpiresAt' => $r['invitation_expires_at'],
            'activatedAt' => $r['activated_at'],
            'createdAt' => $r['created_at'],
        ], $rows);

        return $this->json([
            'items' => $items, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize,
            'allowedRoles' => self::ROLES, 'csrf' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]);
    }

    #[Route('', name: 'api_admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->json(['error' => 'Jeton de sécurité invalide. Rechargez la page.'], 403);
        }
        if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            return $this->json(['error' => 'Corps JSON requis.'], 415);
        }
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \InvalidArgumentException('Objet JSON requis.'); }
            $row = $this->validateRow($data, false);
        } catch (\JsonException|\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        if ($this->emailExists($row['email'])) {
            return $this->json(['error' => 'Cette adresse e-mail possède déjà un compte.'], 409);
        }
        try {
            $created = $this->createAccounts([$row]);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Un compte avec cette adresse existe déjà.'], 409);
        }
        return $this->json(['created' => $created[0]], 201);
    }

    #[Route('/import', name: 'api_admin_users_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->json(['error' => 'Jeton de sécurité invalide. Rechargez la page.'], 403);
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() === false || $file->getSize() > 1048576) {
            return $this->json(['error' => 'Fichier CSV requis (maximum 1 Mo).'], 422);
        }
        try {
            $rows = $this->parseCsv($file);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        $seen = [];
        $ready = [];
        $errors = [];
        $skipped = 0;
        foreach ($rows as $i => $source) {
            try {
                $row = $this->validateRow($source, true);
                if (isset($seen[$row['email']])) {
                    throw new \InvalidArgumentException('Adresse e-mail répétée dans le fichier.');
                }
                $seen[$row['email']] = true;
                if ($this->emailExists($row['email'])) {
                    ++$skipped; // Compte préexistant conservé sans modification
                    continue;
                }
                $ready[] = $row;
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['line' => $i + 2, 'message' => $e->getMessage()];
            }
        }
        $preview = $request->query->get('dry_run') === '1';
        if ($errors !== []) {
            return $this->json(['preview' => $preview, 'toCreate' => count($ready), 'skipped' => $skipped, 'errors' => $errors], 422);
        }
        if ($preview) {
            return $this->json(['preview' => true, 'toCreate' => count($ready), 'skipped' => $skipped, 'errors' => []]);
        }
        try {
            $created = $this->createAccounts($ready);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Un compte a été créé entre la simulation et l’import. Relancez la simulation.'], 409);
        }
        return $this->json(['created' => $created, 'createdCount' => count($created), 'skipped' => $skipped]);
    }

    #[Route('/{id<\d+>}/reinvite', name: 'api_admin_users_reinvite', methods: ['POST'])]
    public function reinvite(int $id, Request $request): JsonResponse
    {
        if (!$this->validCsrf($request)) {
            return $this->json(['error' => 'Jeton de sécurité invalide.'], 403);
        }
        $conn = $this->em->getConnection();
        return $conn->transactional(function (Connection $c) use ($id): JsonResponse {
            $r = $c->fetchAssociative(
                'SELECT u.email, u.is_active, p.user_id FROM users u JOIN user_profiles p ON p.user_id = u.id WHERE u.id = :id FOR UPDATE OF u, p',
                ['id' => $id],
            );
            if ($r === false) { return $this->json(['error' => 'Compte introuvable.'], 404); }
            if (in_array($r['is_active'], [true, 1, '1', 't', 'true'], true)) {
                return $this->json(['error' => 'Le compte est déjà actif.'], 409);
            }
            $token = bin2hex(random_bytes(32));
            $expiry = new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC'));
            $c->update('user_profiles', [
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => $expiry->format('Y-m-d H:i:sP'),
            ], ['user_id' => $id]);
            return $this->json(['email' => $r['email'], 'token' => $token, 'expiresAt' => $expiry->format(DATE_ATOM)]);
        });
    }

    private function validCsrf(Request $request): bool
    {
        $raw = $request->headers->get('X-CSRF-Token');
        return is_string($raw) && $this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $raw));
    }

    private function emailExists(string $email): bool
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM users WHERE LOWER(email) = :email LIMIT 1', ['email' => $email],
        ) !== false;
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function validateRow(array $input, bool $bulk): array
    {
        foreach (['email', 'first_name', 'last_name', 'phone', 'job_title', 'role'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                throw new \InvalidArgumentException('Valeur invalide pour ' . $key . '.');
            }
        }
        $email = mb_strtolower(trim($input['email'] ?? ''));
        $first = trim($input['first_name'] ?? '');
        $last = trim($input['last_name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $job = trim($input['job_title'] ?? '');
        $role = strtoupper(trim($input['role'] ?? 'ROLE_AGENT'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            throw new \InvalidArgumentException('Adresse e-mail incorrecte.');
        }
        if ($first === '' || mb_strlen($first) > 80 || $last === '' || mb_strlen($last) > 80) {
            throw new \InvalidArgumentException('Nom et prénom obligatoires (80 caractères maximum chacun).');
        }
        if (mb_strlen($phone) > 32 || mb_strlen($job) > 120) {
            throw new \InvalidArgumentException('Téléphone ou fonction trop long.');
        }
        if (!in_array($role, self::ROLES, true) || ($bulk && $role === 'ROLE_ADMIN')) {
            throw new \InvalidArgumentException('Rôle non autorisé : ' . $role . '.');
        }
        return ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'job_title' => $job, 'role' => $role];
    }

    /** @return list<array<string, string>> */
    private function parseCsv(UploadedFile $file): array
    {
        $content = file_get_contents($file->getPathname());
        if (!is_string($content) || $content === '' || str_contains($content, "\0")) {
            throw new \InvalidArgumentException('CSV vide ou invalide.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new \InvalidArgumentException('Le CSV doit être encodé en UTF-8.');
        }
        $firstLine = strtok($content, "\r\n") ?: '';
        $sep = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'w+');
        if (!$stream) { throw new \InvalidArgumentException('Lecture CSV impossible.'); }
        fwrite($stream, $content);
        rewind($stream);
        $headers = fgetcsv($stream, 0, $sep, '"', '');
        if (!is_array($headers)) { fclose($stream); throw new \InvalidArgumentException('En-tête CSV manquant.'); }
        $headers = array_map(static fn (string $h): string => strtolower(trim($h)), $headers);
        if (count($headers) !== count(array_unique($headers)) || array_diff(['email','first_name','last_name','role'], $headers)) {
            fclose($stream);
            throw new \InvalidArgumentException('Colonnes obligatoires : email;first_name;last_name;role. Colonnes optionnelles : phone;job_title.');
        }
        if (array_diff($headers, ['email','first_name','last_name','role','phone','job_title'])) {
            fclose($stream); throw new \InvalidArgumentException('Colonne CSV inconnue.');
        }
        $rows = [];
        while (($values = fgetcsv($stream, 0, $sep, '"', '')) !== false) {
            if ($values === [null]) { continue; }
            if (count($values) !== count($headers)) {
                fclose($stream); throw new \InvalidArgumentException('Nombre de colonnes incorrect à la ligne ' . (count($rows) + 2) . '.');
            }
            $rows[] = array_combine($headers, $values);
            if (count($rows) > 200) { fclose($stream); throw new \InvalidArgumentException('Import limité à 200 utilisateurs par fichier.'); }
        }
        fclose($stream);
        if (!$rows) { throw new \InvalidArgumentException('Aucune ligne de données.'); }
        return $rows;
    }

    /** @param list<array<string, string>> $rows @return list<array{email:string,token:string,expiresAt:string}> */
    private function createAccounts(array $rows): array
    {
        $conn = $this->em->getConnection();
        $created = [];
        $conn->beginTransaction();
        try {
            foreach ($rows as $row) {
                // Chaque compte commence désactivé. Aucun mot de passe transmis par CSV.
                $user = (new User())
                    ->setEmail($row['email'])
                    ->setRoles([$row['role']])
                    ->setIsActive(false);
                $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(48))));
                $this->em->persist($user);
                $this->em->flush();
                $token = bin2hex(random_bytes(32));
                $expires = new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC'));
                $conn->insert('user_profiles', [
                    'user_id' => $user->getId(),
                    'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
                    'phone' => $row['phone'] === '' ? null : $row['phone'],
                    'job_title' => $row['job_title'] === '' ? null : $row['job_title'],
                    'invitation_token_hash' => hash('sha256', $token),
                    'invitation_expires_at' => $expires->format('Y-m-d H:i:sP'),
                    'activated_at' => null,
                    'created_at' => gmdate('Y-m-d H:i:sP'),
                ]);
                $created[] = ['email' => $row['email'], 'token' => $token, 'expiresAt' => $expires->format(DATE_ATOM)];
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->em->clear();
            throw $e;
        }
        return $created;
    }
}
