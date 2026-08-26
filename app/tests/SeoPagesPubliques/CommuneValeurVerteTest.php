<?php

declare(strict_types=1);

namespace App\Tests\SeoPagesPubliques;

use App\Tests\Publication\ProvisionneModelePublie;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vague 2 SEO (#362, épic #336) — page publique d'une commune, cible « valeur verte
 * {commune} ». Calquée sur le hub départemental (#337) : la page n'existe que si la
 * commune est réellement mesurée au millésime publié, sinon 404. La meta robots est
 * pilotée par le gating d'indexation (#361) : une commune sous le seuil est servie en
 * noindex (repli), jamais retirée par un 404.
 */
final class CommuneValeurVerteTest extends WebTestCase
{
    use ProvisionneModelePublie;

    private const HOTE = 'valeurverte.localhost';

    public function testUnSlugDeCommuneInconnuEst404(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/haute-garonne/atlantis');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnSlugDeDepartementInconnuEst404(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/atlantide/toulouse');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSansMillesimePublieLaPageNExistePas(): void
    {
        $client = $this->naviguer();
        // Commune connue du référentiel et maille calculée, mais aucun millésime publié.
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerMaille('31001', coefDpe: '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}');
        $client->request('GET', '/valeur-verte/haute-garonne/toulouse');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUneCommuneHorsCouvertureEst404MemeAvecUnMillesimePublie(): void
    {
        $client = $this->naviguer();
        // Toulouse existe au référentiel mais n'a aucune maille servie au millésime.
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
        $client->request('GET', '/valeur-verte/haute-garonne/toulouse');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUneCommuneMesureeEstAccessible(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/haute-garonne/toulouse');

        self::assertResponseIsSuccessful();
    }

    public function testLaPageCibleLeNomDeLaCommune(): void
    {
        $crawler = $this->commune('haute-garonne', 'toulouse');

        $titre = mb_strtolower((string) $crawler->filter('title')->text());
        self::assertStringContainsString('valeur verte', $titre);
        self::assertStringContainsString('toulouse', $titre);
        self::assertStringContainsString('Toulouse', $crawler->filter('h1')->text());
    }

    public function testUneCommuneLocaleEstIndexableEtSonCanonicalPorteLesDeuxSlugs(): void
    {
        $crawler = $this->commune('haute-garonne', 'toulouse');

        self::assertGreaterThanOrEqual(70, mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content')));
        self::assertSame('index,follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertStringEndsWith('/valeur-verte/haute-garonne/toulouse', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testUneCommuneSousLeSeuilEstServieEnNoindex(): void
    {
        $client = $this->naviguer();
        $this->publier();
        // Muret : maille locale mais fortement rapprochée du département (pooling < 0.5)
        // → gating #361 la bascule en noindex, sans pour autant la retirer (200, pas 404).
        $this->semerCommune('31002', 'Muret', '31');
        $this->semerMaille('31002', coefDpe: '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}', poidsPooling: 0.3);

        $crawler = $client->request('GET', '/valeur-verte/haute-garonne/muret');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex,follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testLaPageAfficheDecoteSurcoteEtGains(): void
    {
        $crawler = $this->commune('haute-garonne', 'toulouse');

        // Gains de cran (comme le hub) : E→C = +35 %, C→B = +8 %.
        self::assertStringContainsString('35', $crawler->filter('[data-mesure="gain-ec"]')->text());
        self::assertStringContainsString('8', $crawler->filter('[data-mesure="gain-cb"]')->text());
        // Décote/surcote par étiquette : au moins la classe C, avec une valeur chiffrée.
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-classe="C"]')->count());
        self::assertMatchesRegularExpression('/\d/', $crawler->filter('[data-classe="C"]')->text());
    }

    public function testLaPageAfficheLePrixAuM2QuandIlEstDisponible(): void
    {
        $texte = $this->commune('haute-garonne', 'toulouse')->filter('[data-prix-m2]')->text();

        // publier() sème des ventes de médiane 2 500 €/m².
        self::assertStringContainsString('2 500', $texte);
    }

    public function testLaPageAnnonceSourceMillesimeEtFiabilite(): void
    {
        $crawler = $this->commune('haute-garonne', 'toulouse');
        $texte = $crawler->filter('body')->text();

        self::assertMatchesRegularExpression('/DVF|notaires/u', $texte);
        self::assertStringContainsString('DPE', $texte);
        self::assertStringContainsString('2025', $texte, 'Le millésime publié doit être affiché (critère d\'acceptation).');
        // Fiabilité E-E-A-T : n=120, rayon 10 km → indicateur Solide.
        self::assertStringContainsString('Solide', $crawler->filter('[data-fiabilite]')->text());
    }

    public function testLaPagePorteUnJsonLdPlaceContenuDansLeDepartement(): void
    {
        $crawler = $this->commune('haute-garonne', 'toulouse');

        $brut = $crawler->filter('script[type="application/ld+json"]')->text();
        $donnees = json_decode($brut, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($donnees);

        self::assertSame('WebPage', $donnees['@type']);
        self::assertSame('Place', $donnees['about']['@type']);
        self::assertSame('Toulouse', $donnees['about']['name']);
        self::assertSame('Place', $donnees['about']['containedInPlace']['@type']);
        self::assertSame('Haute-Garonne', $donnees['about']['containedInPlace']['name']);
    }

    public function testLaPageEstMiseEnCachePubliquement(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/haute-garonne/toulouse');

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('s-maxage=3600', $cache);
    }

    private function commune(string $slugDepartement, string $slugCommune): \Symfony\Component\DomCrawler\Crawler
    {
        $client = $this->naviguer();
        $this->publier();
        $crawler = $client->request('GET', '/valeur-verte/'.$slugDepartement.'/'.$slugCommune);
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
     * Toulouse (31001) mesurée localement, avec un B publiable (cran C→B) et un prix
     * de marché de médiane 2 500 €/m². Maille pleinement locale → indexable (#361).
     */
    private function publier(): void
    {
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerMaille('31001', coefDpe: '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}', calculeLe: '2026-02-08 04:12:00');
        foreach ([2000.0, 2500.0, 3000.0] as $prix) {
            $this->semerVente('31001', $prix);
        }
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
    }
}
