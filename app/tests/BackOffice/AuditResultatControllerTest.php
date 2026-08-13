<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page de restitution dédiée (§5.3, #31) : elle ne s'atteint qu'après une saisie
 * (le résultat vit en session). Un accès direct renvoie vers l'écran de saisie.
 */
final class AuditResultatControllerTest extends WebTestCase
{
    public function testSansResultatEnSessionRenvoieVersLaSaisie(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://valeurverte.localhost/audit/resultat');

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertTrue(str_ends_with($location, '/audit'), 'Doit rediriger vers la saisie, pas : '.$location);
    }
}
