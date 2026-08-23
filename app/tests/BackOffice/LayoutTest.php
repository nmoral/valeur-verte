<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\Tests\Support\ConnecteUnPro;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Coquille applicative de l'espace pro (mockup back-office). Le gabarit se vérifie
 * **servi par une vraie requête** et non rendu à sec : depuis #306 les routes de la
 * zone sont épinglées sur `app.`, et `path()` ne rend des liens relatifs que si le
 * contexte de requête est celui de la zone. Rendre le gabarit hors requête produirait
 * des URL absolues et testerait autre chose que ce qu'un pro voit.
 */
final class LayoutTest extends WebTestCase
{
    use ConnecteUnPro;

    public function testLaCoquilleDeLEspaceProPorteMarqueNavigationEtContenu(): void
    {
        $client = static::createClient();
        $this->connecterPro($client);

        $client->request('GET', self::urlPro('/rapports'));

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // Coquille : marque, navigation, zone principale.
        self::assertStringContainsString('Valeur Verte', $html);
        self::assertStringContainsString('<aside', $html);
        self::assertStringContainsString('<main', $html);
        self::assertStringContainsString('Configuration', $html);
        self::assertStringContainsString('Compte', $html);
        // Feuille de style du design system reliée.
        self::assertStringContainsString('back-office.css', $html);

        // Le menu pointe vers de vraies routes, pas des liens morts.
        self::assertStringContainsString('href="/rapports"', $html);
        self::assertStringContainsString('href="/couts"', $html);
        self::assertStringContainsString('href="/bouquets"', $html);
        self::assertStringContainsString('href="/marque"', $html);
        self::assertStringNotContainsString('href="#"', $html);
    }
}
