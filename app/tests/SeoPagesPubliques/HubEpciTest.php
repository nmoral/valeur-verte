<?php

declare(strict_types=1);

namespace App\Tests\SeoPagesPubliques;

use App\Tests\Publication\ProvisionneModelePublie;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Vague 2 SEO (#363, épic #336) — hub EPCI, nœud d'autorité intermédiaire de la
 * cascade commune → EPCI → département. URL `/valeur-verte/epci/{slug}` (préfixe
 * `epci/` distinct pour éviter la collision avec `/valeur-verte/{departement}`).
 * La page n'existe que si l'EPCI est réellement mesuré au millésime publié — sinon
 * 404. Elle liste ses communes membres (annuaire), en ne liant que les indexables
 * (#361), et remonte vers le hub départemental.
 */
final class HubEpciTest extends WebTestCase
{
    use ProvisionneModelePublie;

    private const HOTE = 'valeurverte.localhost';
    private const COEF = '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}';

    public function testUnSlugInconnuEst404(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/epci/narnia-agglomeration');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSansMillesimePublieLeHubNExistePas(): void
    {
        $client = $this->naviguer();
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $client->request('GET', '/valeur-verte/epci/bordeaux-metropole');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnEpciNonMesureEst404(): void
    {
        $client = $this->naviguer();
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
        $client->request('GET', '/valeur-verte/epci/bordeaux-metropole');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnEpciMesureEstAccessible(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/epci/bordeaux-metropole');

        self::assertResponseIsSuccessful();
    }

    public function testLeHubCibleLeNomDeLEpciEtEstIndexable(): void
    {
        $crawler = $this->hub();

        $titre = mb_strtolower((string) $crawler->filter('title')->text());
        self::assertStringContainsString('valeur verte', $titre);
        self::assertStringContainsString('bordeaux métropole', $titre);
        self::assertStringContainsString('Bordeaux Métropole', $crawler->filter('h1')->text());
        self::assertGreaterThanOrEqual(70, mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content')));
        self::assertSame('index,follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/valeur-verte/epci/bordeaux-metropole', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testLeHubAfficheLesChiffresAgreges(): void
    {
        $crawler = $this->hub();

        self::assertStringContainsString('35', $crawler->filter('[data-mesure="gain-ec"]')->text());
        self::assertStringContainsString('8', $crawler->filter('[data-mesure="gain-cb"]')->text());
        self::assertStringContainsString('2', $crawler->filter('[data-mesure="zones"]')->text());
    }

    public function testLeHubAnnonceLaSourceEtLeMillesime(): void
    {
        $texte = $this->hub()->filter('body')->text();

        self::assertMatchesRegularExpression('/DVF|notaires/u', $texte);
        self::assertStringContainsString('DPE', $texte);
        self::assertStringContainsString('2025', $texte);
    }

    public function testLAnnuaireNeLieQueLesCommunesIndexables(): void
    {
        $crawler = $this->hub();
        $liens = $this->cheminsDesLiens($crawler);

        // Bordeaux (locale, indexable) est liée ; Bruges (repli #361) reste en texte.
        self::assertContains('/valeur-verte/gironde/bordeaux', $liens);
        self::assertNotContains('/valeur-verte/gironde/bruges', $liens);
        // Mais Bruges figure bien à l'annuaire (en texte).
        self::assertStringContainsString('Bruges', $crawler->filter('body')->text());
    }

    public function testLeHubRemonteVersLeDepartement(): void
    {
        self::assertContains('/valeur-verte/gironde', $this->cheminsDesLiens($this->hub()));
    }

    public function testLeHubPorteUnJsonLdContenuDansLeDepartement(): void
    {
        $donnees = json_decode(
            $this->hub()->filter('script[type="application/ld+json"]')->text(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($donnees);

        $lieu = $donnees['about'];
        self::assertIsArray($lieu);
        self::assertSame('Bordeaux Métropole', $lieu['name']);

        $contenants = $lieu['containedInPlace'];
        self::assertIsArray($contenants);
        $premier = $contenants[0];
        self::assertIsArray($premier);
        self::assertSame('Gironde', $premier['name']);
    }

    public function testLeHubEstMisEnCachePubliquement(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/epci/bordeaux-metropole');

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('s-maxage=3600', $cache);
    }

    public function testLeHubPorteUnFilDAriane(): void
    {
        // Fil d'Ariane #364 : Accueil › Département › EPCI.
        self::assertSame(['Accueil', 'Gironde', 'Bordeaux Métropole'], $this->filAriane($this->hub()));
    }

    /**
     * @return list<mixed> les noms du fil d'Ariane (JSON-LD BreadcrumbList), dans l'ordre
     */
    private function filAriane(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $donnees = json_decode((string) $node->textContent, true);
            if (!\is_array($donnees) || 'BreadcrumbList' !== ($donnees['@type'] ?? null)) {
                continue;
            }
            $items = $donnees['itemListElement'];
            self::assertIsArray($items);
            $noms = [];
            foreach ($items as $item) {
                self::assertIsArray($item);
                $noms[] = $item['name'];
            }

            return $noms;
        }

        self::fail('Aucun JSON-LD BreadcrumbList trouvé sur la page.');
    }

    /**
     * @return list<string> les chemins (path) de tous les liens de la page
     */
    private function cheminsDesLiens(Crawler $crawler): array
    {
        return $crawler->filter('a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );
    }

    public function testLeHubNAfficheAucunCommercial(): void
    {
        // Vitrine restreinte (#385) : plus de connexion ni de création de compte.
        $crawler = $this->hub();

        self::assertSame(0, $crawler->filter('nav a[href="/login"]')->count());
        self::assertSame(0, $crawler->filter('a[data-lien="inscription"]')->count());
    }

    private function hub(): Crawler
    {
        $client = $this->naviguer();
        $this->publier();
        $crawler = $client->request('GET', '/valeur-verte/epci/bordeaux-metropole');
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
     * Bordeaux Métropole (Gironde) : Bordeaux mesurée localement (indexable),
     * Bruges fortement rapprochée du département (pooling 0.3 → repli #361).
     */
    private function publier(): void
    {
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->rattacherCommuneEpci('33063', '243300316');
        $this->semerMaille('33063', coefDpe: self::COEF, calculeLe: '2026-02-08 04:12:00');
        $this->semerCommune('33065', 'Bruges', '33');
        $this->rattacherCommuneEpci('33065', '243300316');
        $this->semerMaille('33065', coefDpe: self::COEF, calculeLe: '2026-02-08 04:12:00', poidsPooling: 0.3);
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
    }
}
