<?php

declare(strict_types=1);

namespace App\Vitrine;

use App\Erreur\Zone;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Vitrine restreinte (#385) : le temps d'observer l'usage du site et du
 * simulateur, la zone pro (sous-domaine app.) est fermée. Toute visite y est
 * redirigée vers l'accueil public — y compris pour un pro déjà authentifié.
 *
 * Priorité élevée : on tranche **avant** le routage et la sécurité, sans quoi un
 * visiteur non connecté serait d'abord renvoyé vers la page de connexion. Seule la
 * requête maître est concernée (une sous-requête de rendu ne doit pas être
 * recoupée), et la sonde de santé (#313) répond toujours.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final readonly class VerrouillageEspaceProListener
{
    private const CHEMIN_SANTE = '/sante';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requete = $event->getRequest();

        if (self::CHEMIN_SANTE === $requete->getPathInfo()) {
            return;
        }

        if (Zone::EspacePro !== Zone::depuisHote($requete->getHost())) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ));
    }
}
