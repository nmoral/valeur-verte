<?php

declare(strict_types=1);

namespace App\Tests\SeoPagesPubliques;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Vague 1 SEO (#337, épic #336) — page-outil P0. Une landing publique optimisée
 * sur les requêtes « outil » (calculateur valeur verte, simulateur plus-value DPE)
 * qui porte le différenciateur maison : la mesure se fait *par commune*.
 *
 * Contrairement à l'étude (#48/#224), cette page ne dépend d'aucun millésime
 * publié : c'est un aimant à trafic qui existe en permanence et convertit vers
 * l'inscription (le calculateur réel est le parcours produit, pas une
 * réimplémentation ici).
 */
final class PageOutilCalculateurTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';
    private const CHEMIN = '/calculateur';

    public function testLaPageEstAccessibleSansAuthentification(): void
    {
        $client = $this->naviguer();
        $client->request('GET', self::CHEMIN);

        self::assertResponseIsSuccessful();
    }

    public function testLaPageCibleLesRequetesOutil(): void
    {
        $titre = mb_strtolower((string) $this->page()->filter('title')->text());

        self::assertStringContainsString('calculateur', $titre);
        self::assertStringContainsString('valeur verte', $titre);
    }

    public function testLeH1PorteLeDifferenciateurCommunal(): void
    {
        $h1 = mb_strtolower($this->page()->filter('h1')->text());

        // « …par commune » : le grain communal est le cœur du positionnement.
        self::assertStringContainsString('commune', $h1);
    }

    public function testLaPageEstIndexableEtPorteSesMetaDeReferencement(): void
    {
        $crawler = $this->page();

        self::assertGreaterThanOrEqual(70, mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content')));
        self::assertStringContainsString('index', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertStringEndsWith(self::CHEMIN, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testLaPageConvertitVersLInscription(): void
    {
        $crawler = $this->page();

        self::assertSame(
            'http://valeurverte.localhost/inscription',
            $crawler->filter('a[data-lien="inscription"]')->attr('href'),
        );
    }

    public function testLaPageAnnonceLaSourceEtLeGrainDesDonnees(): void
    {
        $texte = $this->page()->filter('body')->text();

        // Source obligatoire (critère d'acceptation) : ventes notaires / DVF + DPE.
        self::assertMatchesRegularExpression('/DVF|notaires/u', $texte);
        self::assertStringContainsString('DPE', $texte);
    }

    public function testLaPageRamèneALAccueil(): void
    {
        $crawler = $this->page();

        self::assertSame(
            'http://valeurverte.localhost/',
            $crawler->filter('a[data-lien="accueil"]')->attr('href'),
        );
    }

    public function testLePiedDePageMeneAuxPagesLegales(): void
    {
        $liens = $this->page()->filter('footer a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );

        foreach (['/mentions-legales', '/confidentialite', '/cgu', '/cgv'] as $attendu) {
            self::assertContains($attendu, $liens, \sprintf('Le pied de page doit mener à %s.', $attendu));
        }
    }

    public function testLaPageEstMiseEnCachePubliquement(): void
    {
        $client = $this->naviguer();
        $client->request('GET', self::CHEMIN);

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        // Valeur exacte : le proxy partagé garde la landing une heure pile.
        self::assertStringContainsString('s-maxage=3600', $cache, 'Une landing statique ne se recalcule pas à chaque visite.');
    }

    private function page(): Crawler
    {
        $client = $this->naviguer();
        $crawler = $client->request('GET', self::CHEMIN);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function naviguer(): KernelBrowser
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_HOST', self::HOTE);

        return $client;
    }
}
