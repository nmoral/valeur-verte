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
 * Vitrine restreinte (#385) : quand elle est active, la zone pro (sous-domaine
 * app.) redirige vers l'accueil public — y compris pour un pro authentifié (la
 * redirection tombe avant la sécurité). L'admin et le public ne sont pas touchés ;
 * la sonde de santé répond toujours. Éteinte, le listener ne fait rien.
 */
final class VerrouillageEspaceProListenerTest extends TestCase
{
    private const URL_ACCUEIL = 'https://valeurverte.localhost/';

    public function testLEspaceProEstRedirigeVersLAccueil(): void
    {
        $event = $this->ecouter(active: true, hote: 'app.valeurverte.localhost');

        $reponse = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame(self::URL_ACCUEIL, $reponse->getTargetUrl());
    }

    public function testEteintLEspaceProResteAccessible(): void
    {
        $event = $this->ecouter(active: false, hote: 'app.valeurverte.localhost');

        self::assertNull($event->getResponse());
    }

    public function testLaZonePubliqueNestPasTouchee(): void
    {
        $event = $this->ecouter(active: true, hote: 'valeurverte.localhost');

        self::assertNull($event->getResponse());
    }

    public function testLAdminNestPasTouche(): void
    {
        $event = $this->ecouter(active: true, hote: 'admin.valeurverte.localhost');

        self::assertNull($event->getResponse());
    }

    public function testLaSanteRepondMemeSurLEspacePro(): void
    {
        // La sonde du conteneur ne doit pas être aveuglée par la fermeture (#313).
        $event = $this->ecouter(active: true, hote: 'app.valeurverte.localhost', chemin: '/sante');

        self::assertNull($event->getResponse());
    }

    public function testUneSousRequeteNestJamaisRedirigee(): void
    {
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://app.valeurverte.localhost/'),
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->listener(active: true)($event);

        self::assertNull($event->getResponse());
    }

    private function ecouter(bool $active, string $hote, string $chemin = '/'): RequestEvent
    {
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://'.$hote.$chemin),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->listener($active)($event);

        return $event;
    }

    private function listener(bool $active): VerrouillageEspaceProListener
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')
            ->with('home', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn(self::URL_ACCUEIL);

        return new VerrouillageEspaceProListener($active, $urls);
    }
}
