<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CoutsControllerTest extends WebTestCase
{
    public function testLEcranAfficheLaGrilleParDefautPreRemplie(): void
    {
        $client = static::createClient();
        $client->request('GET', '/couts');

        self::assertResponseIsSuccessful();

        // Titre de l'écran et onglet de navigation actif.
        self::assertSelectorTextContains('h1', 'Vos coûts de travaux');
        self::assertSelectorTextContains('nav.side a.on', 'Coûts de travaux');

        $html = (string) $client->getResponse()->getContent();

        // Les sept postes sont présentés avec leur libellé.
        foreach ([
            'Isolation des murs',
            'Isolation des combles',
            'Planchers bas',
            'Menuiseries',
            'Pompe à chaleur air/eau',
            'Eau chaude sanitaire',
            'VMC double flux',
        ] as $libelle) {
            self::assertStringContainsString($libelle, $html);
        }

        // Valeurs nationales pré-remplies (§9.2) et unités de chiffrage.
        self::assertStringContainsString('value="120"', $html);
        self::assertStringContainsString('value="14000"', $html);
        self::assertStringContainsString('€/m²', $html);
    }
}
