<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Interdit la mise en cache des pages servies à un utilisateur connecté :
 * après une déconnexion, le bouton « précédent » du navigateur re-sollicite le
 * serveur (et retombe sur le login) au lieu d'afficher une page authentifiée
 * restée dans l'historique.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class PagesConnecteesNonCachees
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (null === $this->tokenStorage->getToken()?->getUser()) {
            return;
        }

        $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
    }
}
