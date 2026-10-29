<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountActivationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    /** L'accès public est autorisé explicitement dans security.yaml ; jamais de rôles dans cette action. */
    #[Route('/api/activate-account', name: 'api_activate_account', methods: ['POST'])]
    public function activate(Request $request): JsonResponse
    {
        if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            return $this->json(['error' => 'JSON requis.'], 415);
        }
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON incorrect.'], 422);
        }
        if (!is_array($data)) { return $this->json(['error' => 'Données incorrectes.'], 422); }
        $token = $data['token'] ?? null;
        $password = $data['password'] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token) || !is_string($password)) {
            return $this->json(['error' => 'Invitation invalide ou mot de passe manquant.'], 422);
        }
        if (mb_strlen($password) < 12 || mb_strlen($password) > 128 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
            return $this->json(['error' => 'Le mot de passe doit contenir 12 à 128 caractères, une minuscule, une majuscule et un chiffre.'], 422);
        }
        $conn = $this->em->getConnection();
        return $conn->transactional(function (Connection $c) use ($token, $password): JsonResponse {
            $row = $c->fetchAssociative(
                "SELECT p.user_id, u.is_active, (p.invitation_expires_at > CURRENT_TIMESTAMP) AS not_expired
                   FROM user_profiles p JOIN users u ON u.id = p.user_id
                  WHERE p.invitation_token_hash = :hash
                  FOR UPDATE OF p, u",
                ['hash' => hash('sha256', $token)],
            );
            if ($row === false || $this->dbBool($row['is_active']) || !$this->dbBool($row['not_expired'])) {
                return $this->json(['error' => 'Invitation expirée, déjà utilisée ou invalide. Contactez un administrateur.'], 400);
            }
            $user = $this->em->find(User::class, (int) $row['user_id']);
            if (!$user instanceof User) { return $this->json(['error' => 'Compte introuvable.'], 404); }
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->setIsActive(true);
            $this->em->flush();
            $c->update('user_profiles', [
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
                'activated_at' => gmdate('Y-m-d H:i:sP'),
            ], ['user_id' => (int) $row['user_id']]);
            return $this->json(['ok' => true, 'message' => 'Compte activé. Vous pouvez vous connecter.']);
        });
    }

    private function dbBool(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 't', 'true'], true);
    }
}
