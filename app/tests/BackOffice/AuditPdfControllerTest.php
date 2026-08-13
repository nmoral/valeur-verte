<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Estimation\CoefficientMaille;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Génération du PDF à l'en-tête du pro (#32) : depuis la page de restitution,
 * le pro télécharge le rapport. Le PDF est produit à partir du résultat en
 * session ; un accès direct, sans saisie préalable, renvoie vers l'écran de
 * saisie (comme la page de restitution).
 */
final class AuditPdfControllerTest extends WebTestCase
{
    private const URL_AUDIT = 'http://valeurverte.localhost/audit';
    private const URL_PDF = 'http://valeurverte.localhost/audit/resultat/pdf';
    private const CODE_INSEE = '31555';

    public function testTelechargeUnPdfApresUneSaisieValide(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);
        $this->soumettre($client);

        $client->request('GET', self::URL_PDF);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $disposition = (string) $client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('rapport-VV-', $disposition);
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    public function testUnAuditTermineArchiveUnRapportImmuable(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        // L'archivage se fait dès la saisie valide, pas au téléchargement : le
        // rapport doit exister avant tout accès au PDF.
        self::assertSame(1, $this->nombreDeRapports($client), 'Un audit terminé doit archiver le rapport.');
    }

    public function testLeTelechargementNeCreePasUnSecondRapport(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);
        $this->soumettre($client);

        $client->request('GET', self::URL_PDF);
        $client->request('GET', self::URL_PDF);

        self::assertSame(1, $this->nombreDeRapports($client), 'Le téléchargement ne doit pas dupliquer le rapport.');
    }

    public function testAccesDirectSansResultatRenvoieVersLaSaisie(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URL_PDF);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertTrue(str_ends_with($location, '/audit'), 'Doit rediriger vers la saisie, pas : '.$location);
    }

    public function testLaPageDeRestitutionProposeLeTelechargement(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille(n: 134, rayonKm: 10));
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);

        $this->soumettre($client);

        self::assertSelectorExists('a[href$="/audit/resultat/pdf"]');
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /**
     * Compte les rapports en base sans passer par l'ORM (filtre tenant neutre) :
     * l'archivage est un effet de bord de la génération, pas une vue filtrée.
     */
    private function nombreDeRapports(KernelBrowser $client): int
    {
        $connexion = $client->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connexion);

        $compte = $connexion->fetchOne('SELECT COUNT(*) FROM rapport');
        self::assertTrue(is_numeric($compte));

        return (int) $compte;
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
        $crawler = $client->request('GET', self::URL_AUDIT);
        self::assertInstanceOf(Crawler::class, $crawler);
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', self::URL_AUDIT, [
            'adresse' => '12 rue des Lilas, Toulouse',
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'code_insee' => self::CODE_INSEE,
            'valeur_bien' => '',
            '_csrf_token' => $token,
        ]);
        $client->followRedirects(false);
    }
}
