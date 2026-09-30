<?php

declare(strict_types=1);

namespace App\Tests\SeoPagesPubliques;

use App\Tests\Publication\ProvisionneModelePublie;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Vague 1 SEO (#337) — hub départemental. Une page par département, cible « valeur
 * verte {département} ». Décision produit : la page n'existe que si le département
 * est réellement mesuré au millésime publié — sinon 404, jamais de page vide.
 */
final class HubDepartementalTest extends WebTestCase
{
    use ProvisionneModelePublie;

    private const HOTE = 'valeurverte.localhost';

    public function testUnSlugDeDepartementInconnuEst404(): void
    {
        $client = $this->naviguer();
        $client->request('GET', '/valeur-verte/atlantide');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSansMillesimePublieLeHubNExistePas(): void
    {
        $client = $this->naviguer();
        $this->prive();
        $client->request('GET', '/valeur-verte/haute-garonne');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnDepartementNonMesureEst404MemeAvecUnMillesimePublie(): void
    {
        $client = $this->naviguer();
        $this->publier();
        // La Gironde (33) est un département valide, mais non semé au millésime.
        $client->request('GET', '/valeur-verte/gironde');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnDepartementMesureEstAccessible(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/haute-garonne');

        self::assertResponseIsSuccessful();
    }

    public function testLeHubCibleLeNomDuDepartement(): void
    {
        $crawler = $this->hub('haute-garonne');

        $titre = mb_strtolower((string) $crawler->filter('title')->text());
        self::assertStringContainsString('valeur verte', $titre);
        self::assertStringContainsString('haute-garonne', $titre);
        self::assertStringContainsString('Haute-Garonne', $crawler->filter('h1')->text());
    }

    public function testLeHubEstIndexableEtSonCanonicalPorteLeSlug(): void
    {
        $crawler = $this->hub('haute-garonne');

        self::assertGreaterThanOrEqual(70, mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content')));
        self::assertStringContainsString('index', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertStringEndsWith('/valeur-verte/haute-garonne', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testLeHubAfficheLesChiffresDuDepartement(): void
    {
        $crawler = $this->hub('haute-garonne');

        // 31 : gain E→C médian = +35 % (comme la carte #224), cran C→B = +8 %.
        self::assertStringContainsString('35', $crawler->filter('[data-mesure="gain-ec"]')->text());
        self::assertStringContainsString('8', $crawler->filter('[data-mesure="gain-cb"]')->text());
    }

    public function testLeHubAnnonceLaSourceEtLeMillesime(): void
    {
        $texte = $this->hub('haute-garonne')->filter('body')->text();

        self::assertMatchesRegularExpression('/DVF|notaires/u', $texte);
        self::assertStringContainsString('DPE', $texte);
        self::assertStringContainsString('2025', $texte, 'Le millésime publié doit être affiché (critère d\'acceptation).');
    }

    public function testLeHubRemonteVersLaCarteNationale(): void
    {
        $liens = $this->hub('haute-garonne')->filter('a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );

        self::assertContains('/carte-valeur-verte-france', $liens, 'Le hub porte le maillage montant vers la carte nationale.');
    }

    public function testLePiedDePageMeneAuxPagesLegales(): void
    {
        $liens = $this->hub('haute-garonne')->filter('footer a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );

        foreach (['/mentions-legales', '/confidentialite', '/cgu', '/cgv'] as $attendu) {
            self::assertContains($attendu, $liens, \sprintf('Le pied de page doit mener à %s.', $attendu));
        }
    }

    public function testLeHubEstMisEnCachePubliquement(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', '/valeur-verte/haute-garonne');

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('s-maxage=3600', $cache);
    }

    public function testLeHubPorteUnFilDAriane(): void
    {
        // Fil d'Ariane #364 : Accueil › Département.
        self::assertSame(['Accueil', 'Haute-Garonne'], $this->filAriane($this->hub('haute-garonne')));
    }

    /**
     * @return list<mixed> les noms du fil d'Ariane (JSON-LD BreadcrumbList), dans l'ordre
     */
    public function testLeHubNAfficheAucunCommercial(): void
    {
        // Vitrine restreinte (#385) : plus de connexion ni de création de compte.
        $crawler = $this->hub('haute-garonne');

        self::assertSame(0, $crawler->filter('nav a[href="/login"]')->count());
        self::assertSame(0, $crawler->filter('a[data-lien="inscription"]')->count());
    }

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

    private function hub(string $slug): Crawler
    {
        $client = $this->naviguer();
        $this->publier();
        $crawler = $client->request('GET', '/valeur-verte/'.$slug);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function naviguer(): KernelBrowser
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_HOST', self::HOTE);

        return $client;
    }

    public function testLeHubRedescendVersSesEpci(): void
    {
        // Maillage descendant (#364) : le hub liste ses EPCI mesurés, liés (les
        // hubs EPCI sont toujours indexables — pas de gating ici).
        $liens = $this->hub('haute-garonne')->filter('a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );

        self::assertContains('/valeur-verte/epci/toulouse-metropole', $liens);
    }

    public function testLeHubAfficheLesHypothesesDuBlocPourquoiIci(): void
    {
        $client = $this->naviguer();
        $this->publier(); // gain E→C médian du 31 = 35 % → levier départemental dérivé « fort ».
        // Marqueur départemental : marché détendu → hypothèse cohérente avec le levier.
        $this->semerMarqueur(nom: 'marche', valeur: 'detendu', codeDepartement: '31');

        $crawler = $client->request('GET', '/valeur-verte/haute-garonne');
        self::assertResponseIsSuccessful();

        $bloc = $crawler->filter('.pourquoi-ici');
        self::assertSame(1, $bloc->count());
        self::assertStringContainsString('Marché détendu', $bloc->text());
        self::assertGreaterThanOrEqual(1, $bloc->filter('.hypothese .badge')->count());
        // Au niveau département : aucune phrase mesurée (pas de « .fait »).
        self::assertSame(0, $bloc->filter('.fait')->count());
    }

    /**
     * La Haute-Garonne (31) mesurée localement, avec un B publiable (cran C→B).
     * Toulouse (31001) est rattachée à Toulouse Métropole (maillage descendant #364).
     */
    private function publier(): void
    {
        $this->semerMaille('31001', coefDpe: '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}', calculeLe: '2026-02-08 04:12:00');
        $this->semerMaille('31002', coefDpe: '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}', calculeLe: '2026-02-08 04:12:00');
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerEpci('243100518', 'Toulouse Métropole');
        $this->rattacherCommuneEpci('31001', '243100518');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
    }
}
