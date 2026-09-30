<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pages légalement obligatoires (#228) : mentions légales (LCEN art. 6-III),
 * politique de confidentialité (RGPD art. 13) et CGU. La CGV (offre commerciale)
 * est retirée du site public tant que la vente n'est pas ouverte (#57).
 */
final class PagesLegalesControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    /**
     * @return iterable<string, array{string}>
     */
    public static function cheminsLegaux(): iterable
    {
        yield 'mentions légales' => ['/mentions-legales'];
        yield 'confidentialité' => ['/confidentialite'];
        yield 'CGU' => ['/cgu'];
    }

    /**
     * @dataProvider cheminsLegaux
     */
    public function testChaquePageLegaleEstAccessibleSansCompte(string $chemin): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.$chemin);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
    }

    /**
     * Vitrine restreinte (#385) : la nav publique partagée (pages légales
     * comprises) ne propose plus ni connexion ni création de compte.
     *
     * @dataProvider cheminsLegaux
     */
    public function testLaNavPubliqueNeProposePlusDeCommercial(string $chemin): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.$chemin);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('nav a[href="/login"]')->count());
        $creation = $crawler->filter('nav a')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Créer un compte'),
        );
        self::assertSame(0, $creation->count());
    }

    /**
     * Ces pages ne dépendent d'aucune session et ne changent qu'au déploiement :
     * elles doivent rester indexables et cachables par un proxy partagé.
     *
     * @dataProvider cheminsLegaux
     */
    public function testChaquePageLegaleEstIndexableEtCachablePubliquement(string $chemin): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.$chemin);

        self::assertStringContainsString('index', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=', $cacheControl);
    }

    /**
     * La LCEN énumère les informations qui doivent figurer : une page qui
     * afficherait l'intitulé sans la valeur ne remplit pas l'obligation.
     *
     * @dataProvider champsObligatoiresDesMentions
     */
    public function testLesMentionsLegalesRenseignentChaqueChampExigeParLaLcen(string $champ): void
    {
        $crawler = $this->page('/mentions-legales');

        $valeur = $crawler->filter(\sprintf('[data-mention="%s"]', $champ));
        self::assertSame(1, $valeur->count(), \sprintf('La mention « %s » est attendue sur la page.', $champ));
        self::assertNotSame('', trim($valeur->text()), \sprintf('La mention « %s » est vide.', $champ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function champsObligatoiresDesMentions(): iterable
    {
        yield 'raison sociale' => ['editeur.raison_sociale'];
        yield 'forme juridique' => ['editeur.forme_juridique'];
        yield 'immatriculation' => ['editeur.immatriculation'];
        yield 'siège' => ['editeur.siege'];
        yield 'directeur de publication' => ['editeur.directeur_publication'];
        yield 'contact' => ['editeur.contact'];
        yield 'hébergeur' => ['hebergeur.identite'];
        yield 'adresse de l\'hébergeur' => ['hebergeur.adresse'];
        yield 'téléphone de l\'hébergeur' => ['hebergeur.telephone'];
    }

    /**
     * Ces informations vivent en configuration : elles changent (siège, statut,
     * hébergeur) sans qu'on ait à toucher au HTML.
     */
    public function testLesMentionsLegalesAffichentLIdentiteConfiguree(): void
    {
        $crawler = $this->page('/mentions-legales');

        /** @var array<string, string> $editeur */
        $editeur = static::getContainer()->getParameter('app.editeur');
        self::assertSame($editeur['raison_sociale'], trim($crawler->filter('[data-mention="editeur.raison_sociale"]')->text()));
        self::assertSame($editeur['siege'], trim($crawler->filter('[data-mention="editeur.siege"]')->text()));

        /** @var array<string, string> $hebergeur */
        $hebergeur = static::getContainer()->getParameter('app.hebergeur');
        self::assertSame($hebergeur['identite'], trim($crawler->filter('[data-mention="hebergeur.identite"]')->text()));
    }

    /**
     * RGPD art. 13 : chaque traitement doit annoncer sa finalité, sa base légale
     * et sa durée de conservation. Un tableau incomplet n'informe personne.
     */
    public function testLaConfidentialiteDecritChaqueTraitementDeBoutEnBout(): void
    {
        $crawler = $this->page('/confidentialite');

        $traitements = $crawler->filter('[data-traitement]');
        self::assertGreaterThanOrEqual(3, $traitements->count(), 'Compte pro, prospects du widget et emails sont au minimum attendus.');

        $traitements->each(static function (Crawler $traitement): void {
            $nom = (string) $traitement->attr('data-traitement');
            foreach (['finalite', 'base-legale', 'duree'] as $rubrique) {
                $cellule = $traitement->filter(\sprintf('[data-rubrique="%s"]', $rubrique));
                self::assertSame(1, $cellule->count(), \sprintf('Traitement « %s » : rubrique « %s » absente.', $nom, $rubrique));
                self::assertNotSame('', trim($cellule->text()), \sprintf('Traitement « %s » : rubrique « %s » vide.', $nom, $rubrique));
            }
        });
    }

    public function testLaConfidentialiteDecritLEmpreinteDEffacementAntiAbus(): void
    {
        // #332 : conserver une empreinte après un effacement (#308) ne tient que si
        // c'est documenté et proportionné — finalité, base légale et durée bornée.
        $ligne = $this->page('/confidentialite')->filter('[data-traitement="empreinte-effacement"]');
        self::assertSame(1, $ligne->count(), 'Le traitement de l\'empreinte d\'effacement doit figurer.');

        $texte = mb_strtolower($ligne->text());
        self::assertStringContainsString('empreinte', $texte);
        self::assertStringContainsString('abus', $texte, 'La finalité anti-abus doit être annoncée.');
        self::assertStringContainsString('12 mois', $texte, 'La durée de conservation bornée doit être annoncée.');
    }

    public function testLaConfidentialiteNommeLesDestinatairesEtLaVoieDeRecours(): void
    {
        $crawler = $this->page('/confidentialite');

        $texte = $crawler->filter('main')->text();
        self::assertStringContainsString('Brevo', $texte, 'Le sous-traitant qui reçoit les emails doit être nommé.');
        self::assertStringContainsString('CNIL', $texte, 'Le droit d\'introduire une réclamation doit être indiqué.');
        foreach (['accès', 'rectification', 'effacement', 'opposition'] as $droit) {
            self::assertStringContainsString($droit, $texte, \sprintf('Le droit d\'%s doit être mentionné.', $droit));
        }
    }

    /**
     * Aucun traceur n'est déposé aujourd'hui : la page doit le dire, faute de quoi
     * le lecteur ne peut pas savoir qu'il n'a rien à consentir.
     */
    public function testLaConfidentialiteTraiteLeSujetDesCookies(): void
    {
        $crawler = $this->page('/confidentialite');

        self::assertSame(1, $crawler->filter('#cookies')->count());
        self::assertStringContainsString('session', $crawler->filter('#cookies')->text());
    }

    /**
     * Le cœur du risque du produit : une estimation statistique prise pour une
     * évaluation immobilière. Les CGU doivent le dire noir sur blanc.
     */
    public function testLesCguQualifientLEstimationEtBornentLaResponsabilite(): void
    {
        $crawler = $this->page('/cgu');

        $texte = $crawler->filter('main')->text();
        self::assertStringContainsString('ne constitue pas une évaluation immobilière', $texte);
        self::assertSame(1, $crawler->filter('#responsabilite')->count());
        self::assertSame(1, $crawler->filter('#propriete-intellectuelle')->count());
        self::assertStringContainsString('droit français', $texte);
    }

    public function testLesCguPortentUneVersionDatee(): void
    {
        $crawler = $this->page('/cgu');

        $version = $crawler->filter('[data-version-cgu]');
        self::assertSame(1, $version->count(), 'Une version datée est nécessaire pour prouver ce qui a été accepté.');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $version->attr('data-version-cgu'));
    }

    /**
     * #59 : DVF et DPE sont réutilisés sous Licence Ouverte Etalab, qui impose la
     * paternité — nommer la source (producteur) et la licence. Les mentions légales
     * portent l'attribution canonique vers laquelle renvoient toutes les autres
     * surfaces (footer, CGU, widget, PDF) ; elle ne doit pas disparaître en silence.
     */
    public function testLesMentionsLegalesAttribuentLesSourcesSousLicenceOuverte(): void
    {
        $sources = $this->page('/mentions-legales')->filter('#sources');

        self::assertSame(1, $sources->count(), 'La section « Sources des données » est obligatoire (Licence Ouverte, paternité).');
        $texte = $sources->text();
        self::assertStringContainsString('Licence Ouverte', $texte);
        self::assertStringContainsString('DVF', $texte);
        self::assertStringContainsString('Direction générale des finances publiques', $texte);
        self::assertStringContainsString('ADEME', $texte);
    }

    /**
     * #59 : la CGU est l'une des surfaces où l'obligation de mention s'applique. Elle
     * n'a pas à recopier l'attribution complète mais doit renvoyer à la Licence
     * Ouverte et à la mention de source des mentions légales.
     */
    public function testLesCguRenvoientALaLicenceOuverteEtALaMentionDeSource(): void
    {
        $crawler = $this->page('/cgu');

        $texte = $crawler->filter('main')->text();
        self::assertStringContainsString('Licence Ouverte', $texte);
        self::assertSame(
            1,
            $crawler->filter('#propriete-intellectuelle a[href="/mentions-legales"]')->count(),
            'La CGU doit pointer vers la mention de source des mentions légales.',
        );
    }

    /**
     * #57 : le service est désormais ouvert au public, sans compte ni abonnement.
     * La CGU ne doit plus se restreindre à « des professionnels », ni supposer la
     * création d'un compte ou un abonnement payant.
     */
    public function testLesCguNeSupposentNiCompteNiAbonnementNiProfession(): void
    {
        $texte = mb_strtolower($this->page('/cgu')->filter('main')->text());

        self::assertStringNotContainsString('professionnel', $texte);
        self::assertStringNotContainsString('abonnement', $texte);
        self::assertStringNotContainsString('création d\'un compte', $texte);
    }

    /**
     * #57 : la responsabilité de restitution incombe à qui reprend le chiffre, pas
     * à un « professionnel » nommé — le service s'adresse à tout utilisateur.
     */
    public function testLaResponsabiliteDeRestitutionIncombeAQuiUtiliseLeChiffre(): void
    {
        $texte = $this->page('/cgu')->filter('#statut-estimation')->text();

        self::assertMatchesRegularExpression('/utilisateur|toute personne/i', $texte);
        self::assertMatchesRegularExpression('/présente|communique|diffuse|reprend/i', $texte);
        self::assertStringContainsString('responsable', mb_strtolower($texte));
    }

    /**
     * #57 : l'offre commerciale n'est pas ouverte (site public et gratuit, sans
     * compte payant). La CGV est retirée du site public ; son URL historique
     * redirige vers les CGU tant que la vente n'est pas ouverte.
     */
    public function testLaCgvRedirigeVersLesCguTantQueLaVenteNestPasOuverte(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/cgv');

        self::assertResponseRedirects('/cgu', 301);
    }

    private function page(string $chemin): Crawler
    {
        $client = static::createClient();
        $crawler = $client->request('GET', 'http://'.self::HOTE.$chemin);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testLaRetentionAnnonceeSuitLaDecisionD10(): void
    {
        // Ce que la page promet doit être ce que le cron applique (#294) :
        // anonymisation à 24 mois, suppression à 3 ans.
        $client = static::createClient();
        $crawler = $client->request('GET', 'http://'.self::HOTE.'/confidentialite');

        self::assertResponseIsSuccessful();
        $prospects = $crawler->filter('[data-traitement="prospects-widget"] [data-rubrique="duree"]')->text();
        self::assertStringContainsString('24 mois', $prospects);
        self::assertMatchesRegularExpression('/anonymis/i', $prospects, 'La page doit annoncer l\'anonymisation, pas la seule suppression.');
        self::assertStringContainsString('3 ans', $prospects);

        $rapports = $crawler->filter('[data-traitement="rapports"] [data-rubrique="duree"]')->text();
        self::assertMatchesRegularExpression('/anonymis/i', $rapports);
        self::assertStringContainsString('3 ans', $rapports);
    }
}
