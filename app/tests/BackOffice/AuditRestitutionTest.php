<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Estimation\CoefficientMaille;
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

    private static function maille(int $n, ?int $rayonKm): CoefficientMaille
    {
        return CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: $rayonKm,
            n: $n,
            erreurMediane: 17.22,
            coefDpe: ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
        );
    }

    private function soumettre(KernelBrowser $client, string $valeurBien = ''): void
    {
        $client->followRedirects();
        $crawler = $client->request('GET', self::URL);
        self::assertInstanceOf(Crawler::class, $crawler);
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', self::URL, [
            'adresse' => '12 rue des Lilas, Toulouse',
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'code_insee' => self::CODE_INSEE,
            'valeur_bien' => $valeurBien,
            '_csrf_token' => $token,
        ]);
    }
}
