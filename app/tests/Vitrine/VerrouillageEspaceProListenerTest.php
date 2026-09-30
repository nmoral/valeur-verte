<?php

declare(strict_types=1);

namespace App\Tests\Vitrine;

use App\Vitrine\VerrouillageEspaceProListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Vitrine restreinte (#385) : la zone pro (sous-domaine app.) est fermée le temps
 * de l'observation d'usage. Toute visite y est redirigée vers l'accueil public,
 * y compris pour un pro déjà authentifié — la redirection tombe avant la sécurité.
 * L'admin et le public ne sont pas touchés ; la sonde de santé répond toujours.
 */
final class VerrouillageEspaceProListenerTest extends TestCase
{
    private const URL_ACCUEIL = 'https://valeurverte.localhost/';

    public function testLEspaceProEstRedirigeVersLAccueil(): void
    {
        $event = $this->ecouter('app.valeurverte.localhost');

        $reponse = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame(self::URL_ACCUEIL, $reponse->getTargetUrl());
    }

    public function testLaZonePubliqueNestPasTouchee(): void
    {
        $event = $this->ecouter('valeurverte.localhost');

        self::assertNull($event->getResponse());
    }

    public function testLAdminNestPasTouche(): void
    {
        $event = $this->ecouter('admin.valeurverte.localhost');

        self::assertNull($event->getResponse());
    }

    public function testLaSanteRepondMemeSurLEspacePro(): void
    {
        // La sonde du conteneur ne doit pas être aveuglée par la fermeture (#313).
        $event = $this->ecouter('app.valeurverte.localhost', chemin: '/sante');

        self::assertNull($event->getResponse());
    }

    public function testUneSousRequeteNestJamaisRedirigee(): void
    {
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://app.valeurverte.localhost/'),
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->listener()($event);

        self::assertNull($event->getResponse());
    }

    private function ecouter(string $hote, string $chemin = '/'): RequestEvent
    {
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://'.$hote.$chemin),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->listener()($event);

        return $event;
    }

    private function listener(): VerrouillageEspaceProListener
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')
            ->with('home', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn(self::URL_ACCUEIL);

        return new VerrouillageEspaceProListener($urls);
    }
}
