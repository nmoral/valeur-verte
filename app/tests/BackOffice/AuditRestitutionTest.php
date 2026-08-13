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
        $client = static::createClient();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Saisie validée', $html);
        self::assertStringContainsString('134 transactions', $html);
        self::assertStringContainsString('rayon de 10 km', $html);
        self::assertStringContainsString('2024', $html);
        self::assertStringContainsString('€', $html);
    }

    public function testUneMailleSolideAfficheLIndicateurVert(): void
    {
        $client = static::createClient();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        self::assertSelectorTextContains('.fiabilite', 'Solide');
        self::assertSelectorExists('.fiabilite-vert');
    }

    public function testUneMailleFragileAfficheLeBandeau(): void
    {
        $client = static::createClient();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 40, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Saisie validée', $html);
        self::assertSelectorExists('.fiabilite-rouge');
        self::assertSelectorExists('.banner-fiabilite');
    }

    public function testUneCommuneHorsCouvertureResteValideMaisSansChiffre(): void
    {
        $client = static::createClient();
        // Aucun coefficient défini pour ce code INSEE : estimation indisponible.

        $this->soumettre($client);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Saisie validée', $html);
        self::assertStringContainsString('estimation', strtolower($html));
        self::assertSelectorNotExists('.fiabilite');
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

    private function soumettre(KernelBrowser $client): void
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
            '_csrf_token' => $token,
        ]);
    }
}
