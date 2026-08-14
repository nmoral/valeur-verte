<?php

declare(strict_types=1);

namespace App\Erreur\Maintenance;

use App\Erreur\Zone;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bascule maintenance pilotée par la variable d'environnement APP_MAINTENANCE
 * (#197). Active, elle coupe les zones publique/pro en 503 (page habillée) mais
 * épargne l'admin, pour que l'exploitation reste possible pendant l'intervention.
 *
 * Priorité élevée : on tranche avant le routage et la sécurité. Seule la requête
 * maître est concernée — la page d'erreur est rendue par une sous-requête, qu'il
 * ne faut pas recouper (sinon boucle).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class ModeMaintenanceListener
{
    private const DELAI_REESSAI_SECONDES = 120;

    public function __construct(
        private bool $maintenanceActive,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->maintenanceActive || !$event->isMainRequest()) {
            return;
        }

        if (Zone::Admin === Zone::depuisHote($event->getRequest()->getHost())) {
            return;
        }

        throw new ServiceUnavailableHttpException(self::DELAI_REESSAI_SECONDES);
    }
}
