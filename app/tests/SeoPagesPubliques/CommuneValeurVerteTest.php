<?php

declare(strict_types=1);

namespace App\Tests\SeoPagesPubliques;

use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
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
        $client = $this->naviguer();
        $this->publier();
        // En test, le prix vient du double en mémoire (PrixMailleDbal → data.vente
        // est remplacé par alias, cf. services.yaml when@test).
        $this->prix($client)->definir('31001', 2500.0);

        $crawler = $client->request('GET', '/valeur-verte/haute-garonne/toulouse');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2 500', $crawler->filter('[data-prix-m2]')->text());
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

        $lieu = $donnees['about'];
        self::assertIsArray($lieu);
        self::assertSame('Place', $lieu['@type']);
        self::assertSame('Toulouse', $lieu['name']);

        $contenant = $lieu['containedInPlace'];
        self::assertIsArray($contenant);
        self::assertSame('Place', $contenant['@type']);
        self::assertSame('Haute-Garonne', $contenant['name']);
    }

    public function testLeJsonLdExposeLesStatistiquesMesureesEnDataset(): void
    {
        // Données structurées #338 : la WebPage porte un Dataset (mainEntity) qui
        // expose en PropertyValue la décote/surcote et le prix au m² par étiquette,
        // avec la provenance E-E-A-T (licence Etalab, millésime, sources DVF/DPE).
        $client = $this->naviguer();
        $this->publier();
        $this->prix($client)->definir('31001', 2500.0);
        $crawler = $client->request('GET', '/valeur-verte/haute-garonne/toulouse');
        self::assertResponseIsSuccessful();

        $dataset = $this->donneesWebPage($crawler)['mainEntity'];
        self::assertIsArray($dataset);
        self::assertSame('Dataset', $dataset['@type']);
        self::assertIsString($dataset['license']);
        self::assertStringContainsStringIgnoringCase('etalab', $dataset['license']);
        self::assertSame('2025', $dataset['temporalCoverage']);

        $couverture = $dataset['spatialCoverage'];
        self::assertIsArray($couverture);
        self::assertSame('Toulouse', $couverture['name']);

        $sources = json_encode($dataset['isBasedOn'], \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('DVF', $sources);
        self::assertStringContainsString('DPE', $sources);

        $variables = $dataset['variableMeasured'];
        self::assertIsArray($variables);
        // Décote/surcote C (base D, exp(0.11)−1 ≈ +12 %) exposée en PropertyValue.
        self::assertContains(
            ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette C', 'value' => 12, 'unitText' => '%'],
            $variables,
        );
        // Prix au m² C : médiane 2500 € pondérée par l'effet C (2500·exp(0.11) ≈ 2791 €).
        self::assertContains(
            ['@type' => 'PropertyValue', 'name' => 'Prix au m² étiquette C', 'value' => 2791, 'unitText' => '€/m²'],
            $variables,
        );
    }

    /**
     * @return array<mixed> le JSON-LD de type WebPage
     */
    private function donneesWebPage(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $donnees = json_decode((string) $node->textContent, true);
            if (\is_array($donnees) && 'WebPage' === ($donnees['@type'] ?? null)) {
                return $donnees;
            }
        }

        self::fail('Aucun JSON-LD WebPage trouvé sur la page.');
    }

    public function testLaPageNAfficheAucunCommercial(): void
    {
        // Vitrine restreinte (#385) : plus de connexion ni de création de compte.
        $client = $this->naviguer();
        $this->publier();
        $crawler = $client->request('GET', '/valeur-verte/haute-garonne/toulouse');
        self::assertResponseIsSuccessful();

        self::assertSame(0, $crawler->filter('nav a[href="/login"]')->count());
        self::assertSame(0, $crawler->filter('a[data-lien="inscription"]')->count());
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

    public function testLaPageRemonteVersSonEpci(): void
    {
        // Maillage montant #364 : la page commune lie vers son hub EPCI.
        $liens = $this->cheminsDesLiens($this->commune('haute-garonne', 'toulouse'));

        self::assertContains('/valeur-verte/epci/toulouse-metropole', $liens);
    }

    public function testUneCommuneSansEpciNAffichePasDeLienEpci(): void
    {
        // Commune sans rattachement EPCI : pas de lien mort, pas de 500 — juste
        // pas de lien EPCI (le lien montant département reste, lui).
        $client = $this->naviguer();
        $this->semerCommune('31099', 'Solo', '31');
        $this->semerMaille('31099', coefDpe: '{"D":0.0,"C":0.11,"E":-0.19}');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $crawler = $client->request('GET', '/valeur-verte/haute-garonne/solo');
        self::assertResponseIsSuccessful();

        $epci = array_filter(
            $this->cheminsDesLiens($crawler),
            static fn (string $chemin): bool => str_starts_with($chemin, '/valeur-verte/epci/'),
        );
        self::assertSame([], $epci);
    }

    public function testLaPagePorteUnFilDArianeMontant(): void
    {
        // Fil d'Ariane #364 (BreadcrumbList) : Accueil › Département › EPCI › Commune.
        self::assertSame(
            ['Accueil', 'Haute-Garonne', 'Toulouse Métropole', 'Toulouse'],
            $this->filAriane($this->commune('haute-garonne', 'toulouse')),
        );
    }

    /**
     * @return list<mixed> les noms du fil d'Ariane (JSON-LD BreadcrumbList), dans l'ordre
     */
    private function filAriane(\Symfony\Component\DomCrawler\Crawler $crawler): array
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
    private function cheminsDesLiens(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('a')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );
    }

    public function testLaPageAfficheLeBlocPourquoiIci(): void
    {
        $client = $this->naviguer();
        $this->publier(); // Toulouse : gain E→C = 35 %, n = 120.
        // Marqueurs communaux : marché détendu + levier fort → hypothèse cohérente.
        $this->semerMarqueur(nom: 'marche', valeur: 'detendu', codeInsee: '31001');
        $this->semerMarqueur(nom: 'levier_renovation', valeur: 'fort', typeMarqueur: 'mesure', codeInsee: '31001');

        $crawler = $client->request('GET', '/valeur-verte/haute-garonne/toulouse');
        self::assertResponseIsSuccessful();

        $bloc = $crawler->filter('.pourquoi-ici');
        self::assertSame(1, $bloc->count(), 'Le bloc « Pourquoi ici ? » est rendu.');
        // Le fait mesuré ouvre le bloc (gain E→C + nombre de ventes).
        self::assertStringContainsString('+35 %', $bloc->text());
        self::assertStringContainsString('120 ventes', $bloc->text());
        // Une hypothèse contextuelle badgée « Hypothèse ».
        self::assertStringContainsString('Marché détendu', $bloc->text());
        self::assertGreaterThanOrEqual(1, $bloc->filter('.hypothese .badge')->count());
        self::assertStringContainsString('Hypothèse', $bloc->filter('.hypothese .badge')->first()->text());
        // Critère d'acceptation #341 : le fait mesuré est visuellement DISTINCT des
        // hypothèses — porté par « .fait », sans badge « Hypothèse ».
        self::assertSame(1, $bloc->filter('.fait')->count());
        self::assertStringContainsString('+35 %', $bloc->filter('.fait')->text());
        self::assertSame(0, $bloc->filter('.fait .badge')->count());
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
        // Le double de prix vit dans le conteneur : sans figer le kernel, la requête
        // le reconstruirait et perdrait le prix défini avant elle.
        $client->disableReboot();

        return $client;
    }

    private function prix(KernelBrowser $client): FournisseurPrixMailleEnMemoire
    {
        $double = $client->getContainer()->get(FournisseurPrixMailleEnMemoire::class);
        self::assertInstanceOf(FournisseurPrixMailleEnMemoire::class, $double);

        return $double;
    }

    /**
     * Toulouse (31001) mesurée localement, avec un B publiable (cran C→B). Maille
     * pleinement locale (poids_pooling absent) → indexable (#361).
     */
    private function publier(): void
    {
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerMaille('31001', coefDpe: '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}', calculeLe: '2026-02-08 04:12:00');
        // Rattachement EPCI pour le maillage montant commune → EPCI (#364).
        $this->semerEpci('243100518', 'Toulouse Métropole');
        $this->rattacherCommuneEpci('31001', '243100518');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
    }
}
