<?php

declare(strict_types=1);

namespace App\Tests\Vitrine;

use App\Tests\Support\ConnecteUnPro;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vitrine restreinte (#385) : la zone pro (app.) est fermée. Le verrou tombe avant
 * la sécurité, donc aussi bien un visiteur anonyme qu'un pro authentifié sont
 * renvoyés vers l'accueil public (jamais vers la page de connexion).
 */
final class EspaceProFermeTest extends WebTestCase
{
    use ConnecteUnPro;

    private const ACCUEIL = 'http://valeurverte.localhost/';

    public function testUnVisiteurAnonymeEstRedirigeVersLAccueil(): void
    {
        $client = static::createClient();

        $client->request('GET', self::urlPro('/'));

        self::assertResponseRedirects(self::ACCUEIL);
    }

    public function testUnProAuthentifieEstAussiRedirigeVersLAccueil(): void
    {
        $client = static::createClient();
        $this->connecterPro($client);

        $client->request('GET', self::urlPro('/'));

        self::assertResponseRedirects(self::ACCUEIL);
    }
}
