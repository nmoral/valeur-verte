<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Estimation\CoefficientMaille;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use App\Tests\Support\ConnecteUnPro;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Régénération du PDF depuis l'instantané immuable (#33, §9.1, D5) : un rapport
 * déjà produit se retélécharge à l'identique par sa référence, sans recalcul et
 * indépendamment des tables mutables. Une référence inconnue renvoie 404.
 */
final class RapportPdfControllerTest extends WebTestCase
{
    use ConnecteUnPro;

    private const URL_AUDIT = 'http://'.self::HOTE_PRO.'/audit';
    private const CODE_INSEE = '31555';

    public function testRegenereLePdfDUnRapportDepuisSaReference(): void
    {
        $client = $this->client();
        $this->coefficients($client)->definir(self::CODE_INSEE, self::maille());
        $this->prix($client)->definir(self::CODE_INSEE, 1635.0);
        $reference = $this->produireUnRapport($client);

        $client->request('GET', 'http://'.self::HOTE_PRO.'/rapport/'.$reference.'/pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    public function testUneReferenceInconnueRenvoie404(): void
    {
        $client = static::createClient();
        $this->connecterPro($client);

        $client->request('GET', 'http://'.self::HOTE_PRO.'/rapport/VV-19990101-ZZZZZZ/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Produit un rapport via le mode audit et renvoie sa référence (lue dans
     * l'en-tête Content-Disposition du PDF téléchargé).
     */
    private function produireUnRapport(KernelBrowser $client): string
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

        $client->request('GET', 'http://'.self::HOTE_PRO.'/audit/resultat/pdf');
        $disposition = (string) $client->getResponse()->headers->get('Content-Disposition');
        if (1 !== preg_match('/rapport-(VV-\d{8}-[0-9A-Z]{6})\.pdf/', $disposition, $correspondance)) {
            self::fail('Le PDF téléchargé doit porter une référence de rapport : '.$disposition);
        }

        return $correspondance[1];
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $this->connecterPro($client);
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

    private static function maille(): CoefficientMaille
    {
        return CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: 10,
            n: 134,
            erreurMediane: 17.22,
            coefDpe: ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
        );
    }
}
