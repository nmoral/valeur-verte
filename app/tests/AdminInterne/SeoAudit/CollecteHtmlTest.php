<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\CollecteHtml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CollecteHtmlTest extends TestCase
{
    public function testRecupereLeCodeEtLeCorpsDUnePage(): void
    {
        $client = new MockHttpClient([new MockResponse('<title>Bonjour</title>', ['http_code' => 200])]);

        $page = (new CollecteHtml($client))->recuperer('https://site.test/');

        self::assertSame('https://site.test/', $page->url);
        self::assertSame(200, $page->codeHttp);
        self::assertStringContainsString('Bonjour', $page->html);
    }

    public function testNeSuitPasLesRedirections(): void
    {
        // Une URL du sitemap qui répond 301 doit être vue comme telle, pas suivie.
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => 'https://site.test/ailleurs']])]);

        $page = (new CollecteHtml($client))->recuperer('https://site.test/ancienne');

        self::assertSame(301, $page->codeHttp);
    }

    public function testUnCodeDErreurNeLevePasDException(): void
    {
        $client = new MockHttpClient([new MockResponse('Introuvable', ['http_code' => 404])]);

        $page = (new CollecteHtml($client))->recuperer('https://site.test/absente');

        self::assertSame(404, $page->codeHttp);
    }

    public function testUneErreurDeTransportDonneUnCodeZero(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'connexion refusée']);
        });

        $page = (new CollecteHtml($client))->recuperer('https://injoignable.test/');

        self::assertSame(0, $page->codeHttp);
    }
}
