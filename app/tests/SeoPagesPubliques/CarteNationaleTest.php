<?php

declare(strict_types=1);

namespace App\Tests\SeoPagesPubliques;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La page-carte nationale (#337) est retirée (#391) : l'étude
 * `/valeur-verte-par-departement` porte désormais la carte **et** l'annuaire des
 * départements. L'URL historique `/carte-valeur-verte-france` reste servie mais
 * **redirige en 301** vers l'étude, pour préserver les backlinks et le SEO.
 */
final class CarteNationaleTest extends WebTestCase
{
    private const CHEMIN = '/carte-valeur-verte-france';
    private const CIBLE = '/valeur-verte-par-departement';

    public function testLUrlHistoriqueRedirigeEn301VersLEtude(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'valeurverte.localhost');

        $client->request('GET', self::CHEMIN);

        self::assertResponseRedirects(self::CIBLE, 301);
    }

    public function testLaRedirectionNeDependPasDUnMillesimePublie(): void
    {
        // Pas de provisionnement de données : la redirection est inconditionnelle
        // (elle ne rend plus la carte, donc n'a plus besoin d'un millésime publié).
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'valeurverte.localhost');

        $client->request('GET', self::CHEMIN);

        self::assertResponseStatusCodeSame(301);
    }
}
