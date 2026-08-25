<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoPresence\Google\JetonAcces;
use App\AdminInterne\SeoPresence\Google\SearchConsoleViaGoogleApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearchConsoleViaGoogleApiTest extends TestCase
{
    private function jetons(?string $jeton): JetonAcces
    {
        return new class($jeton) implements JetonAcces {
            public function __construct(private ?string $jeton)
            {
            }

            public function jeton(): ?string
            {
                return $this->jeton;
            }
        };
    }

    public function testAgregeLesMetriquesEtLesRequetesTop(): void
    {
        $reponse = '{"rows":[
            {"keys":["valeur verte maison"],"clicks":80,"impressions":1000,"ctr":0.08,"position":4.5},
            {"keys":["dpe plus value"],"clicks":20,"impressions":1000,"ctr":0.02,"position":8.5}
        ]}';
        $capture = [];
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$capture, $reponse): MockResponse {
            $capture = ['methode' => $methode, 'url' => $url, 'options' => $options];

            return new MockResponse($reponse);
        });

        $gsc = new SearchConsoleViaGoogleApi($this->jetons('ya29.test'), $client, 'sc-domain:valeur-verte.fr');
        $metriques = $gsc->metriques(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'), 5);

        self::assertNotNull($metriques);
        self::assertSame(100, $metriques->clics);
        self::assertSame(2000, $metriques->impressions);
        self::assertSame(0.05, round($metriques->ctr, 4));
        // Position moyenne pondérée par impressions : (4.5*1000 + 8.5*1000)/2000 = 6.5.
        self::assertSame(6.5, round($metriques->positionMoyenne, 4));
        self::assertCount(2, $metriques->topRequetes);
        self::assertSame('valeur verte maison', $metriques->topRequetes[0]->requete);
        self::assertSame(80, $metriques->topRequetes[0]->clics);

        self::assertSame('POST', $capture['methode']);
        $urlAppelee = $capture['url'];
        self::assertIsString($urlAppelee);
        self::assertStringContainsString(rawurlencode('sc-domain:valeur-verte.fr'), $urlAppelee);
        self::assertStringContainsString('searchAnalytics/query', $urlAppelee);
        $entetes = $capture['options']['headers'] ?? [];
        self::assertIsArray($entetes);
        $jetonPorte = false;
        foreach ($entetes as $entete) {
            if (\is_string($entete) && false !== stripos($entete, 'ya29.test')) {
                $jetonPorte = true;
            }
        }
        self::assertTrue($jetonPorte, 'Le jeton doit être passé en Authorization: Bearer.');
    }

    public function testUnResultatVideDonneDesMetriquesAZeroSansDivisionParZero(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"rows":[]}'));

        $metriques = (new SearchConsoleViaGoogleApi($this->jetons('ya29'), $client, 'sc-domain:x'))->metriques(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'));

        self::assertNotNull($metriques);
        self::assertSame(0, $metriques->clics);
        self::assertSame(0, $metriques->impressions);
        self::assertSame(0.0, $metriques->ctr);
        self::assertSame(0.0, $metriques->positionMoyenne);
        self::assertSame([], $metriques->topRequetes);
    }

    public function testMetriquesNonConfigureRendNull(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{}');
        });

        // Pas de jeton (compte de service absent).
        self::assertNull((new SearchConsoleViaGoogleApi($this->jetons(null), $client, 'sc-domain:x'))->metriques(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31')));
        // Jeton présent mais propriété non renseignée.
        self::assertNull((new SearchConsoleViaGoogleApi($this->jetons('ya29'), $client, ''))->metriques(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31')));
        self::assertSame(0, $appels);
    }

    public function testMetriquesErreurSeDegradeEnNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('timeout'));

        self::assertNull((new SearchConsoleViaGoogleApi($this->jetons('ya29'), $client, 'sc-domain:x'))->metriques(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31')));
    }

    public function testInspecteLEtatDIndexationDUneUrl(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"inspectionResult":{"indexStatusResult":{"coverageState":"Submitted and indexed","verdict":"PASS"}}}'));

        $etat = (new SearchConsoleViaGoogleApi($this->jetons('ya29'), $client, 'sc-domain:valeur-verte.fr'))->indexation('https://valeur-verte.fr/');

        self::assertNotNull($etat);
        self::assertSame('https://valeur-verte.fr/', $etat->url);
        self::assertSame('Submitted and indexed', $etat->couverture);
    }

    public function testIndexationNonConfigureRendNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}'));

        self::assertNull((new SearchConsoleViaGoogleApi($this->jetons(null), $client, 'sc-domain:x'))->indexation('https://valeur-verte.fr/'));
    }
}
