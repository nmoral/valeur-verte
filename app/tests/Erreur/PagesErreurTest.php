<?php

declare(strict_types=1);

namespace App\Tests\Erreur;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pages d'erreur habillées (#197). On boote le noyau en **debug désactivé** pour
 * exercer le rendu de production (les gabarits `errorXXX`), et non la page de
 * debug affichée en dev/test — c'est ce que sert le VPS (APP_ENV=prod).
 */
final class PagesErreurTest extends WebTestCase
{
    private const HOTE_PUBLIC = 'valeurverte.localhost';

    public function testUneUrlInexistanteRendUne404Habillee(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', 'http://'.self::HOTE_PUBLIC.'/cette-page-nexiste-pas');

        self::assertResponseStatusCodeSame(404);
        // Identité de marque, pas le gabarit technique brut de Symfony.
        self::assertSelectorTextContains('body', 'Valeur Verte');
        self::assertStringNotContainsStringIgnoringCase(
            'An Error Occurred',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testLa404ProposeUnRetourVersLAccueilDeLaZone(): void
    {
        $client = static::createClient(['debug' => false]);

        $crawler = $client->request('GET', 'http://'.self::HOTE_PUBLIC.'/cette-page-nexiste-pas');

        $retour = $crawler->filter('a')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Retour'),
        );
        self::assertGreaterThan(0, $retour->count(), 'Un lien de retour est attendu sur la page d\'erreur.');
        self::assertStringContainsString(self::HOTE_PUBLIC, (string) $retour->attr('href'));
    }
}
