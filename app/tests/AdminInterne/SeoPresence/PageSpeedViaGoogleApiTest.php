<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoPresence\Google\PageSpeedViaGoogleApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PageSpeedViaGoogleApiTest extends TestCase
{
    private const REPONSE = '{
        "lighthouseResult": {
            "categories": {"performance": {"score": 0.92}},
            "audits": {
                "largest-contentful-paint": {"numericValue": 2100.4},
                "cumulative-layout-shift": {"numericValue": 0.05},
                "interaction-to-next-paint": {"numericValue": 180.0}
            }
        }
    }';

    public function testMesureLesCoreWebVitals(): void
    {
        $urlAppelee = null;
        $client = new MockHttpClient(static function (string $methode, string $url) use (&$urlAppelee): MockResponse {
            $urlAppelee = $url;

            return new MockResponse(self::REPONSE);
        });

        $vitals = (new PageSpeedViaGoogleApi($client, 'cle-psi'))->mesurer('https://valeurverte.fr/');

        self::assertNotNull($vitals);
        self::assertSame('https://valeurverte.fr/', $vitals->url);
        self::assertSame(2100, $vitals->lcpMs);
        self::assertSame(0.05, $vitals->cls);
        self::assertSame(180, $vitals->inpMs);
        self::assertSame(92, $vitals->scorePerformance);
        self::assertIsString($urlAppelee);
        self::assertStringContainsString('key=cle-psi', $urlAppelee);
        self::assertStringContainsString('url='.rawurlencode('https://valeurverte.fr/'), $urlAppelee);
    }

    public function testNonConfigureRendNullSansAppel(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{}');
        });

        self::assertNull((new PageSpeedViaGoogleApi($client, ''))->mesurer('https://valeurverte.fr/'));
        self::assertSame(0, $appels);
    }

    public function testUneErreurReseauSeDegradeEnNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('timeout'));

        self::assertNull((new PageSpeedViaGoogleApi($client, 'cle-psi'))->mesurer('https://valeurverte.fr/'));
    }

    public function testUnChampAbsentResteNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"lighthouseResult":{"categories":{"performance":{"score":0.5}},"audits":{}}}'));

        $vitals = (new PageSpeedViaGoogleApi($client, 'cle-psi'))->mesurer('https://valeurverte.fr/');

        self::assertNotNull($vitals);
        self::assertSame(50, $vitals->scorePerformance);
        self::assertNull($vitals->lcpMs);
        self::assertNull($vitals->cls);
        self::assertNull($vitals->inpMs);
    }
}
