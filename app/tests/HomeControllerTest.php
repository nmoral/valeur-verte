<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLaHomePubliqueRepondEtPorteLeDiscours(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Montrez-leur la valeur');
    }

    public function testLeSimulateurEstPresentAvecSesSeptClasses(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();
        // Le widget de démonstration et son échelle DPE cliquable (A→G).
        self::assertSelectorExists('#simulateur');
        self::assertCount(7, $crawler->filter('#simulateur .rung'));
        // Les deux modes de réglage et le curseur de surface.
        self::assertSelectorExists('#simulateur [data-role="mode-cur"]');
        self::assertSelectorExists('#simulateur [data-role="mode-tgt"]');
        self::assertSelectorExists('#simulateur input[type="range"][data-role="surface"]');
        // La valeur créée par défaut (E → C, 94 m²) est rendue côté serveur.
        self::assertSelectorTextContains('#simulateur [data-role="valeur"]', '52');
    }

    public function testLaCarteDevisEtSaTableDeTransitionsSontPresentes(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.devis-card h3', 'Le marché paie le passage en C');
        // Table « valeur créée par transition » : E→D, E→C, F→C, C→B.
        self::assertGreaterThanOrEqual(4, $crawler->filter('.devis-card table tbody tr')->count());
    }

    public function testLesDeuxOffresDePrixSontPresentes(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Membre classique');
        self::assertSelectorTextContains('body', 'Offre fondateur');
        self::assertSelectorTextContains('body', '129 €');
        self::assertSelectorTextContains('body', '89 €');
    }

    public function testLaHomeOffreConnexionEtCreationDeCompte(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();

        $connexion = $crawler->filter('nav a')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Connexion'),
        );
        self::assertGreaterThan(0, $connexion->count(), 'Un lien « Connexion » est attendu dans la nav.');
        self::assertSame('/login', $connexion->attr('href'));

        $creation = $crawler->filter('a')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Créer un compte'),
        );
        self::assertGreaterThan(0, $creation->count(), 'Un bouton « Créer un compte » est attendu.');
    }
}
