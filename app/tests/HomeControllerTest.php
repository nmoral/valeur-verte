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
        self::assertSelectorTextContains('h1', 'valeur');
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

    public function testAucuneOffreCommercialeNiTarifNestAffichee(): void
    {
        // Vitrine restreinte (#385) : on masque tout le commercial (tarifs, offres,
        // « deux façons d'entrer »). Le site n'affiche que des infos et le simulateur.
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Membre classique');
        self::assertSelectorTextNotContains('body', 'Offre fondateur');
        // « 300 € » seul matcherait « 68 300 € » (label du simulateur) : on cible
        // les mentions tarifaires sans ambiguïté.
        self::assertSelectorTextNotContains('body', '/ mois');
        self::assertSelectorTextNotContains('body', 'sans engagement');
        self::assertSelectorTextNotContains('body', "Deux façons d'entrer");
    }

    public function testLaNavNeProposePlusConnexionNiCreationDeCompte(): void
    {
        // Vitrine restreinte (#385) : plus d'entrée « Créer un compte » ni de lien
        // « Connexion » dans la nav publique (la connexion reste accessible par URL
        // directe pour l'exploitation).
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();

        $creation = $crawler->filter('a')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Créer un compte'),
        );
        self::assertSame(0, $creation->count(), 'Aucun bouton « Créer un compte » ne doit subsister.');

        $connexionNav = $crawler->filter('nav a[href="'.'/login'.'"]');
        self::assertSame(0, $connexionNav->count(), 'La nav ne doit plus proposer la connexion.');
    }

    public function testLAccesAuSimulateurEstMisEnAvant(): void
    {
        // On donne facilement accès au simulateur, à la place de la zone tarifs.
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();
        $acces = $crawler->filter('a[data-lien="simulateur"]');
        self::assertGreaterThan(0, $acces->count(), 'Un accès au simulateur est attendu.');
        self::assertStringContainsString('#simulateur', (string) $acces->first()->attr('href'));
    }
}
