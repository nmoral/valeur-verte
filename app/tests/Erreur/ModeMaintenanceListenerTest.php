<?php

declare(strict_types=1);

namespace App\Tests\Erreur;

use App\Erreur\Maintenance\ModeMaintenanceListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Bascule maintenance (#197) : quand elle est active, l'app renvoie 503 (page
 * habillée) sur les zones publiques et pro, mais laisse passer l'admin pour que
 * l'exploitation reste possible pendant l'intervention.
 */
final class ModeMaintenanceListenerTest extends TestCase
{
    public function testMaintenanceActiveCoupeLaZonePubliqueEn503(): void
    {
        $this->expectException(ServiceUnavailableHttpException::class);

        $this->ecouter(active: true, hote: 'valeur-verte.fr');
    }

    public function testMaintenanceActiveCoupeAussiLEspacePro(): void
    {
        $this->expectException(ServiceUnavailableHttpException::class);

        $this->ecouter(active: true, hote: 'app.valeur-verte.fr');
    }

    public function testLAdminResteAccessiblePendantLaMaintenance(): void
    {
        $this->ecouter(active: true, hote: 'admin.valeur-verte.fr');

        $this->addToAssertionCount(1); // Aucune exception : l'admin passe.
    }

    public function testMaintenanceInactiveNeCoupeRien(): void
    {
        $this->ecouter(active: false, hote: 'valeur-verte.fr');

        $this->addToAssertionCount(1);
    }

    public function testLaReponseIndiqueUnDelaiDeReessai(): void
    {
        try {
            $this->ecouter(active: true, hote: 'valeur-verte.fr');
            self::fail('Une exception 503 était attendue.');
        } catch (ServiceUnavailableHttpException $e) {
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
        }
    }

    public function testUneSousRequeteNEstJamaisCoupee(): void
    {
        // Le rendu de la page d'erreur passe par une sous-requête : la couper
        // relancerait la maintenance en boucle.
        $listener = new ModeMaintenanceListener(true);
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://valeur-verte.fr/'),
            HttpKernelInterface::SUB_REQUEST,
        );

        $listener($event);

        $this->addToAssertionCount(1);
    }

    private function ecouter(bool $active, string $hote): void
    {
        $listener = new ModeMaintenanceListener($active);
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://'.$hote.'/'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $listener($event);
    }
}
