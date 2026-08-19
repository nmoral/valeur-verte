<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * La page publique de publication du modèle : « c'est à jour ? » (#48) et la carte
 * de validation par département (#224), sur une seule page, sans authentification.
 *
 * Elle ne montre que le millésime **publié** (REG-2) : tant qu'aucun millésime n'a
 * été validé à la main, il n'y a rien à publier — et donc pas de page.
 */
final class PagePublicationTest extends WebTestCase
{
    use ProvisionneModelePublie;

    private const HOTE = 'valeurverte.localhost';
    private const CHEMIN = '/valeur-verte-par-departement';

    public function testSansMillesimePublieLaPageNExistePas(): void
    {
        $client = $this->naviguer();
        $this->prive();
        $client->request('GET', self::CHEMIN);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaPageEstAccessibleSansAuthentification(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', self::CHEMIN);

        self::assertResponseIsSuccessful();
    }

    public function testLaPageDitDeQuandDateLeModele(): void
    {
        $crawler = $this->page();

        self::assertStringContainsString('2025', $crawler->filter('[data-etat="millesime"]')->text());
        self::assertSame('2026-02-08', $crawler->filter('[data-etat="calcule-le"]')->attr('datetime'));
        self::assertSame('2026-02-10', $crawler->filter('[data-etat="publie-le"]')->attr('datetime'));
    }

    public function testLaPageDitCeQueLeModeleCouvre(): void
    {
        $crawler = $this->page();

        self::assertSame('4', $crawler->filter('[data-mesure="zones"] b')->text());
        self::assertSame('2', $crawler->filter('[data-mesure="departements"] b')->text());
        self::assertStringContainsString('2', $crawler->filter('[data-etat="communes"]')->text());
    }

    public function testLeBandeauDePreuveAfficheLesMedianesNationales(): void
    {
        $crawler = $this->page();

        self::assertStringContainsString('13', $crawler->filter('[data-mesure="gain-d"] b')->text());
        self::assertStringContainsString('23', $crawler->filter('[data-mesure="gain-c"] b')->text());
    }

    public function testLaCarteEmbarqueLesMetriquesDeChaqueDepartement(): void
    {
        $crawler = $this->page();

        $donnees = json_decode($crawler->filter('#carte-donnees')->text(), true);

        self::assertIsArray($donnees);
        self::assertSame(760, $donnees['w']);
        self::assertIsArray($donnees['feats']);
        $parDepartement = array_column($donnees['feats'], 'm', 'dept');
        self::assertSame(['n' => 2, 'gain' => 35, 'gainED' => 21, 'dept' => 0], $parDepartement['31']);
        self::assertNull($parDepartement['57'], 'L\'Alsace-Moselle est dessinée sans chiffres.');
    }

    public function testLesConstatsCitentLesDepartementsPubliesEtNonDesValeursFigees(): void
    {
        $texte = $this->page()->filter('.findings')->text();

        // Dérivé des données semées (31 Haute-Garonne +35 %, 09 Ariège +11 %),
        // plus les noms/pourcentages figés de l'ancienne rédaction.
        self::assertStringContainsString('Haute-Garonne', $texte);
        self::assertStringContainsString('35 %', $texte);
        self::assertStringContainsString('Ariège', $texte);
        self::assertStringNotContainsString('Hauts-de-Seine', $texte);
        self::assertStringNotContainsString('Onze départements', $texte);
    }

    public function testLeNombreDeDepartementsAbsentsEstCompteEtNonFige(): void
    {
        // Deux départements publiés sur les ~96 dessinés : le compte des absents
        // est loin de « six » et doit refléter les données, pas un texte figé.
        $texte = $this->page()->filter('.limits')->text();

        self::assertDoesNotMatchRegularExpression('/Six d[ée]partements sont absents/u', $texte);
        self::assertMatchesRegularExpression('/\d+\s+départements sont absents/u', $texte);
    }

    public function testLaPageRamèneALAccueil(): void
    {
        $crawler = $this->page();

        self::assertSame(
            'http://valeurverte.localhost/',
            $crawler->filter('.topbar a[data-lien="accueil"]')->attr('href'),
        );
    }

    public function testLaPageInviteACreerUnCompte(): void
    {
        $crawler = $this->page();

        self::assertSame(
            'http://valeurverte.localhost/inscription',
            $crawler->filter('a[data-lien="inscription"]')->attr('href'),
        );
    }

    public function testLaPageEstIndexableEtPorteSesMetaDeReferencement(): void
    {
        $crawler = $this->page();

        self::assertGreaterThanOrEqual(70, mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content')));
        self::assertStringContainsString('index', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
    }

    /**
     * Page publique à part entière : elle doit ouvrir sur les mentions
     * obligatoires comme la vitrine (#228).
     */
    public function testLePiedDePageMeneAuxPagesLegales(): void
    {
        $crawler = $this->page();

        $liens = $crawler->filter('footer a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );
        foreach (['/mentions-legales', '/confidentialite', '/cgu', '/cgv'] as $attendu) {
            self::assertContains($attendu, $liens, \sprintf('Le pied de page doit mener à %s.', $attendu));
        }
    }

    public function testLaPageEstMiseEnCachePubliquement(): void
    {
        $client = $this->naviguer();
        $this->publier();
        $client->request('GET', self::CHEMIN);

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('s-maxage', $cache, 'Une page publique agrégeant 30 000 mailles ne se recalcule pas à chaque visite.');
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
     * Deux départements publiés : la Haute-Garonne mesurée localement, l'Ariège
     * repliée à l'échelle départementale.
     */
    private function publier(): void
    {
        $this->semerMaille('31001', calculeLe: '2026-02-08 04:12:00');
        $this->semerMaille('31002', calculeLe: '2026-02-08 04:12:00');
        $this->semerMaille('09001', mode: 'departemental', coefDpe: '{"D":0.0,"C":0.05,"E":-0.05}');
        $this->semerMaille('09002', mode: 'departemental', coefDpe: '{"D":0.0,"C":0.05,"E":-0.05}');
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerCommune('09001', 'Foix', '09');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
    }
}
