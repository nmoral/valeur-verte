<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BouquetsControllerTest extends WebTestCase
{
    public function testLEcranListeLeBouquetParDefautAvecSesPostes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/back-office/bouquets');

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('h1', 'Bouquets types');
        self::assertSelectorTextContains('nav.side a.on', 'Bouquets types');

        $html = (string) $client->getResponse()->getContent();

        // Le bouquet « rénovation d'ampleur standard » est actif par défaut (ONB-3)...
        self::assertStringContainsString("Rénovation d'ampleur standard", $html);
        // ...et couvre tous les postes : on en vérifie les extrêmes.
        self::assertStringContainsString('Isolation des murs', $html);
        self::assertStringContainsString('VMC double flux', $html);
    }
}
