<?php
declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Bundle\SecurityBundle\Security;

/** Ferme les sessions ouvertes après suspension ou changement de rôle. */
final class ActiveAccountSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $conn,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Security $security,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['checkAccount', 5]];
    }

    public function checkAccount(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) return;
        $user = $this->security->getUser();
        if (!$user instanceof User || $user->getId() === null) return;

        $row = $this->conn->fetchAssociative('SELECT is_active, roles FROM users WHERE id = :id', ['id' => $user->getId()]);
        $blocked = $row === false || !in_array($row['is_active'], [true, 1, '1', 't', 'true'], true);
        $token = $this->tokenStorage->getToken();
        $roleChanged = false;
        if (!$blocked && $token !== null) {
            $stored = json_decode((string) $row['roles'], true);
            $dbRoles = is_array($stored) ? $stored : [];
            $dbRoles[] = 'ROLE_USER';
            $roleNames = $token->getRoleNames();
            // La comparaison de rôle invalide le jeton de session existant après réaffectation.
            sort($dbRoles);
            sort($roleNames);
            $roleChanged = array_values(array_unique($dbRoles)) !== array_values(array_unique($roleNames));
        }
        if (!$blocked && !$roleChanged) return;

        $this->tokenStorage->setToken(null);
        if ($request->hasPreviousSession()) {
            $request->getSession()->invalidate();
        }
        $event->setController(static fn (): JsonResponse => new JsonResponse([
            'error' => 'Votre accès a été modifié. Veuillez vous reconnecter ou contacter un administrateur.',
        ], 401));
    }
}
