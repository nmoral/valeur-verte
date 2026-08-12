<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\QualificationsRgeViaApiAdeme;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QualificationsRgeViaApiAdemeTest extends TestCase
{
    private const SIRET = '12345678901234';

    public function testUneEntrepriseCertifieeRemonteSesDomainesDistincts(): void
    {
        $urlDemandee = null;
        $client = new MockHttpClient(function (string $methode, string $url) use (&$urlDemandee): MockResponse {
            $urlDemandee = $url;

            return new MockResponse((string) json_encode([
                'total' => 3,
                'results' => [
                    ['siret' => self::SIRET, 'domaine' => 'Isolation des murs', 'site_internet' => 'https://qualit-enr.org'],
                    ['siret' => self::SIRET, 'domaine' => 'Menuiseries extérieures'],
                    ['siret' => self::SIRET, 'domaine' => 'Isolation des murs'],
                ],
            ]));
        });

        $qualification = (new QualificationsRgeViaApiAdeme($client))->pourSiret(Siret::depuis(self::SIRET));

        self::assertSame(StatutRge::Rge, $qualification->statut());
        self::assertSame(['Isolation des murs', 'Menuiseries extérieures'], $qualification->domaines());
        self::assertStringContainsString(self::SIRET, (string) $urlDemandee);
        self::assertNotContains('https://qualit-enr.org', $qualification->domaines(), 'Le site du certificateur ne doit jamais fuiter.');
    }

    public function testUneEntrepriseSansLigneEstNonRge(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode(['total' => 0, 'results' => []])));

        $qualification = (new QualificationsRgeViaApiAdeme($client))->pourSiret(Siret::depuis(self::SIRET));

        self::assertSame(StatutRge::NonRge, $qualification->statut());
        self::assertSame([], $qualification->domaines());
    }

    public function testUneApiIndisponibleDonneUnStatutIndetermine(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('timeout'));

        $qualification = (new QualificationsRgeViaApiAdeme($client))->pourSiret(Siret::depuis(self::SIRET));

        self::assertSame(StatutRge::Indetermine, $qualification->statut(), 'API muette ≠ absence de RGE.');
        self::assertSame([], $qualification->domaines());
    }
}
