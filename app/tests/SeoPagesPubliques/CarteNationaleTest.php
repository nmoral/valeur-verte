<?php

declare(strict_types=1);

namespace App\Tests\SeoPagesPubliques;

use App\Tests\Publication\ProvisionneModelePublie;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Vague 1 SEO (#337) — page-carte nationale. Landing « carte valeur verte France »,
 * aimant à backlinks : elle reprend la carte publiée (#48/#224) et ouvre sur
 * l'annuaire des hubs départementaux. Sans millésime publié, elle n'existe pas.
 */
final class CarteNationaleTest extends WebTestCase
{
    use ProvisionneModelePublie;

    private const HOTE = 'valeurverte.localhost';
    private const CHEMIN = '/carte-valeur-verte-france';

    public function testSansMillesimePublieLaPageNExistePas(): void
    {
        $client = $this->naviguer();
        $this->prive();
        $client->request('GET', self::CHEMIN);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaPageEstAccessibleUneFoisLeMillesimePublie(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', self::CHEMIN);

        self::assertResponseIsSuccessful();
    }

    public function testLaPageCibleCarteValeurVerteFrance(): void
    {
        $crawler = $this->page();

        $titre = mb_strtolower((string) $crawler->filter('title')->text());
        self::assertStringContainsString('carte', $titre);
        self::assertStringContainsString('france', $titre);

        $h1 = mb_strtolower($crawler->filter('h1')->text());
        self::assertStringContainsString('carte', $h1);
        self::assertStringContainsString('france', $h1);
    }

    public function testLaPageEstIndexableEtPorteSesMetaDeReferencement(): void
    {
        $crawler = $this->page();

        self::assertGreaterThanOrEqual(70, mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content')));
        self::assertStringContainsString('index', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertStringEndsWith(self::CHEMIN, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testLaPageEmbarqueLaCarteChoroplethe(): void
    {
        $crawler = $this->page();

        self::assertSame(1, $crawler->filter('#carte-donnees')->count(), 'La carte publiée doit être réutilisée.');
        self::assertSame(1, $crawler->filter('svg#map')->count());
    }

    public function testLaPageOuvreSurLAnnuaireDesHubs(): void
    {
        $crawler = $this->page();

        // La Haute-Garonne est publiée (semée) : son hub doit être lié.
        $liens = $crawler->filter('.annuaire a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );

        self::assertContains('/valeur-verte/haute-garonne', $liens);
        self::assertNotContains('/valeur-verte/gironde', $liens, 'Un département non mesuré ne doit pas figurer à l\'annuaire.');
    }

    public function testLaPageAnnonceLaSourceEtLeMillesime(): void
    {
        $texte = $this->page()->filter('body')->text();

        self::assertMatchesRegularExpression('/DVF|notaires/u', $texte);
        self::assertStringContainsString('DPE', $texte);
        self::assertStringContainsString('2025', $texte, 'Le millésime publié doit être affiché (critère d\'acceptation).');
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

    public function testLePiedDePageMailleLesPagesPiliersSeo(): void
    {
        $liens = $this->page()->filter('footer a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );

        foreach (['/', '/calculateur', '/carte-valeur-verte-france', '/valeur-verte-par-departement'] as $attendu) {
            self::assertContains($attendu, $liens, \sprintf('Le pied de page doit mailler la page pilier %s.', $attendu));
        }
    }

    public function testLaPageRamèneALAccueilEtALInscription(): void
    {
        $crawler = $this->page();

        self::assertSame('http://valeurverte.localhost/', $crawler->filter('a[data-lien="accueil"]')->attr('href'));
        self::assertSame('http://valeurverte.localhost/inscription', $crawler->filter('a[data-lien="inscription"]')->first()->attr('href'));
    }

    public function testLaPageEstMiseEnCachePubliquement(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', self::CHEMIN);

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('s-maxage=3600', $cache, 'Une carte stable ne se recalcule pas à chaque visite.');
    }

    private function page(): Crawler
    {
        $client = $this->naviguer();
        $this->publier();
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

    /**
     * Un millésime publié : la Haute-Garonne mesurée localement (donc un hub réel),
     * l'Ariège repliée à l'échelle départementale.
     */
    private function publier(): void
    {
        $this->semerMaille('31001', coefDpe: '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}', calculeLe: '2026-02-08 04:12:00');
        $this->semerMaille('31002', coefDpe: '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}', calculeLe: '2026-02-08 04:12:00');
        $this->semerMaille('09001', mode: 'departemental', coefDpe: '{"D":0.0,"C":0.05,"E":-0.05}');
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerCommune('09001', 'Foix', '09');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
    }
}
