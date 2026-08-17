<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Restitution (§5.3, #31) : après une saisie valide, l'estimation chiffrée est
 * présentée avec son contexte (effectif, fourchette, rayon, millésime) et son
 * indicateur de fiabilité. Les coefficients et le prix viennent de doubles.
 */
final class AuditRestitutionTest extends WebTestCase
{
    private const URL = 'http://valeurverte.localhost/audit';
    private const CODE_INSEE = '31555';

    public function testUneSaisieValideAfficheLeChiffreEtSonContexte(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('12 rue des Lilas, Toulouse', $html);
        // Contexte porté par les tuiles : effectif, rayon, millésime.
        self::assertSelectorTextContains('.stats', '134');
        self::assertSelectorTextContains('.stats', '10 km');
        self::assertSelectorTextContains('.stats', '2024');
        self::assertStringContainsString('fourchette', $html);
        self::assertStringContainsString('€', $html);
    }

    public function testLaValeurVerteEstPresenteeAvantLeCalculEconomique(): void
    {
        // Remise au propre (#110) : le livrable — la valeur verte créée — mène la
        // page ; le coût et le reste à charge viennent en section secondaire, dessous.
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        $html = (string) $client->getResponse()->getContent();
        $posValeur = strpos($html, 'Valeur verte créée par la rénovation');
        $posCout = strpos($html, 'Coût de la rénovation');
        self::assertIsInt($posValeur, 'La section valeur verte doit être présente.');
        self::assertIsInt($posCout, 'La section coût / reste à charge doit être présente.');
        self::assertLessThan($posCout, $posValeur, 'La valeur verte doit précéder le calcul économique.');
    }

    public function testUneMailleSolideAfficheLIndicateurVert(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        self::assertSelectorTextContains('.fiabilite', 'solide');
        self::assertSelectorExists('.fiabilite-vert');
    }

    public function testUneMailleFragileAfficheLeBandeau(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 40, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('12 rue des Lilas, Toulouse', $html);
        self::assertSelectorExists('.fiabilite-rouge');
        self::assertSelectorExists('.banner-fiabilite');
    }

    public function testUneMailleEpciAfficheLaPorteeIntercommunale(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 60, rayonKm: null, portee: PorteeEstimation::Epci));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        // Cran EPCI (#169) : rayon absent, mais la stat nomme l'intercommunalité
        // au lieu d'annoncer un repli départemental.
        self::assertSelectorTextContains('.stats', 'intercommunal (EPCI)');
    }

    public function testUneMailleDegradeeAfficheLeBandeauDedie(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10, palier: PalierFiabilite::Degrade));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        // Palier dégradé (#169) : bandeau propre, distinct du bandeau Fragile.
        self::assertSelectorExists('.banner-degrade');
        self::assertSelectorTextContains('.banner-degrade', 'dégradée');
    }

    public function testUneClasseFusionneeAfficheLaNoteDeBande(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(
            n: 60,
            rayonKm: null,
            portee: PorteeEstimation::Epci,
            coefDpe: ['D' => 0.0, 'C' => 0.10, 'F' => -0.30],
            bandes: ['F' => 'F', 'G' => 'F'],
        ));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client, dpeAvant: 'G', dpeApres: 'C');

        // Rendu par bande (#169) : la classe G, servie via F, est signalée.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('classe G', $html);
        self::assertStringContainsString('bande F', $html);
    }

    public function testLeProPeutAjusterLaValeurDuBien(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        // E→C, Δ = 0,30 ; valeur du bien forcée à 200 000 € → 200000 × (e^0,30 − 1).
        $this->soumettre($client, valeurBien: '200000');

        self::assertStringContainsString('69 972', (string) $client->getResponse()->getContent());
    }

    public function testLaRestitutionAfficheLePrixDeBaseParDefaut(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        $html = (string) $client->getResponse()->getContent();
        // Prix de base = 1635 × 90 = 147 150 €, issu de la médiane de la commune.
        self::assertStringContainsString('147 150', $html);
        self::assertStringContainsString('médiane de la commune', $html);
    }

    public function testLaRestitutionAfficheLePrixSaisiParLePro(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client, valeurBien: '200000');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('200 000', $html);
        self::assertStringContainsString('valeur que vous avez saisie', $html);
    }

    public function testLaRestitutionMetEnAvantLeFranchissementDuSeuilC(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        // E→C, prix de base 147 150 € : la table décompose E→D puis E→C.
        $this->soumettre($client);

        self::assertSelectorExists('.effet-seuil');
        // E→D (+32 579 €) et E→C (+51 482 €) sont tous deux présents.
        self::assertSelectorTextContains('.effet-seuil', '32 579');
        self::assertSelectorTextContains('.effet-seuil', '51 482');
        // La ligne C est mise en avant comme le seuil qui rémunère.
        self::assertSelectorTextContains('.effet-seuil .palier-seuil', 'C');
        // Un message pédagogique accompagne la table (ici : le pro vise C).
        self::assertSelectorTextContains('.effet-seuil-message', 'rémunère le plus');
    }

    public function testSansContrasteAucuneTableDeSeuilNEstAffichee(): void
    {
        $client = $this->client();
        // Maille ne publiant que D et C : depuis D, un seul palier (D→C) — pas de
        // contraste à montrer, la table ne s'affiche pas.
        $this->coefficients($client)->definir(self::CODE_INSEE, CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: 10,
            n: 134,
            erreurMediane: 17.22,
            coefDpe: ['D' => 0.0, 'C' => 0.10],
        ));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client, dpeAvant: 'D', dpeApres: 'C');

        self::assertSelectorNotExists('.effet-seuil');
    }

    public function testUneCommuneHorsCouvertureResteValideMaisSansChiffre(): void
    {
        $client = $this->client();
        // Aucun coefficient défini pour ce code INSEE : estimation indisponible.

        $this->soumettre($client);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('12 rue des Lilas, Toulouse', $html);
        self::assertStringContainsString('estimation', strtolower($html));
        self::assertSelectorNotExists('.fiabilite');
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        // Sans reboot entre GET (jeton) et POST, le conteneur — donc les doubles
        // configurés en mémoire — survit d'une requête à l'autre.
        $client->disableReboot();

        return $client;
    }

    private function coefficients(KernelBrowser $client): FournisseurCoefficientMailleEnMemoire
    {
        $double = $client->getContainer()->get(FournisseurCoefficientMailleEnMemoire::class);
        self::assertInstanceOf(FournisseurCoefficientMailleEnMemoire::class, $double);

        return $double;
    }

    private function prix(KernelBrowser $client): FournisseurPrixMailleEnMemoire
    {
        $double = $client->getContainer()->get(FournisseurPrixMailleEnMemoire::class);
        self::assertInstanceOf(FournisseurPrixMailleEnMemoire::class, $double);

        return $double;
    }

    /**
     * @param array<string, float>  $coefDpe
     * @param array<string, string> $bandes
     */
    private static function maille(
        int $n,
        ?int $rayonKm,
        PorteeEstimation $portee = PorteeEstimation::Local,
        PalierFiabilite $palier = PalierFiabilite::Standard,
        array $coefDpe = ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
        array $bandes = [],
    ): CoefficientMaille {
        return CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: $rayonKm,
            n: $n,
            erreurMediane: 17.22,
            coefDpe: $coefDpe,
            portee: $portee,
            palier: $palier,
            bandes: $bandes,
        );
    }

    private function soumettre(KernelBrowser $client, string $valeurBien = '', string $dpeAvant = 'E', string $dpeApres = 'C'): void
    {
        $client->followRedirects();
        $crawler = $client->request('GET', self::URL);
        self::assertInstanceOf(Crawler::class, $crawler);
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', self::URL, [
            'adresse' => '12 rue des Lilas, Toulouse',
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => $dpeAvant,
            'dpe_apres' => $dpeApres,
            'code_insee' => self::CODE_INSEE,
            'valeur_bien' => $valeurBien,
            '_csrf_token' => $token,
        ]);
    }
}
