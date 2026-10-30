<?php
declare(strict_types=1);
namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Filet de sécurité sur les contrôleurs métier existants, même s'ils n'ont pas IsGranted. */
final class MunicipalApiPermissionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['enforce', 20]];
    }

    public function enforce(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $method = strtoupper($request->getMethod());
        $read = in_array($method, ['GET', 'HEAD'], true);
        $permission = null;
        if (preg_match('#^/api/businesses(?:/|$)#D', $path)) {
            $permission = $read ? 'commerces.read' : 'commerces.write';
        } elseif (preg_match('#^/api/invoices(?:/|$)#D', $path)) {
            $permission = $read ? 'factures.read' : 'factures.write';
        } elseif (preg_match('#^/api/payments(?:/|$)#D', $path)) {
            $permission = $read ? 'encaissements.read' : 'encaissements.write';
        }
        if ($permission !== null && !$this->security->isGranted($permission)) {
            throw new AccessDeniedException('Permission municipale requise : ' . $permission);
        }
    }
}
