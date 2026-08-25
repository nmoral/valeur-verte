<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\AuditeurOnPage;
use App\AdminInterne\SeoAudit\AuditeurSeo;
use App\AdminInterne\SeoAudit\CollecteHtml;
use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\Regle\RegleCodeHttp;
use App\AdminInterne\SeoAudit\Regle\RegleTitre;
use App\AdminInterne\SeoAudit\Regle\RegleTitresUniques;
use App\AdminInterne\SeoAudit\SourceUrls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AuditeurSeoTest extends TestCase
{
    /**
     * @param array<string, MockResponse> $reponses
     */
    private function auditeur(array $reponses, string ...$urls): AuditeurSeo
    {
        $client = new MockHttpClient(static function (string $methode, string $url) use ($reponses): MockResponse {
            return $reponses[$url] ?? new MockResponse('', ['http_code' => 404]);
        });

        $source = new class(array_values($urls)) implements SourceUrls {
            /** @param list<string> $urls */
            public function __construct(private array $urls)
            {
            }

            public function urls(): array
            {
                return $this->urls;
            }
        };

        return new AuditeurSeo(
            $source,
            new CollecteHtml($client),
            new AuditeurOnPage([new RegleTitre(), new RegleCodeHttp()]),
            [new RegleTitresUniques()],
            'valeurverte.localhost',
        );
    }

    public function testAuditeChaqueUrlDuPerimetreEtVerifieLesRessourcesGlobales(): void
    {
        $reponses = [
            'https://valeurverte.localhost/' => new MockResponse('<title>Accueil valeur verte du bien</title>', ['http_code' => 200]),
            'https://valeurverte.localhost/a-propos' => new MockResponse('<title>À propos de la valeur verte</title>', ['http_code' => 200]),
            'https://valeurverte.localhost/robots.txt' => new MockResponse("User-agent: *\nAllow: /", ['http_code' => 200]),
            'https://valeurverte.localhost/sitemap.xml' => new MockResponse('<?xml version="1.0"?><urlset></urlset>', ['http_code' => 200]),
        ];

        $rapport = $this->auditeur($reponses, 'https://valeurverte.localhost/', 'https://valeurverte.localhost/a-propos')->auditer();

        self::assertSame(2, $rapport->nombreUrls());
        // 2 pages × 2 règles on-page, toutes vertes.
        self::assertSame(4, array_reduce($rapport->pages, static fn (int $n, $p): int => $n + \count($p->constats), 0));
        $codesGlobaux = array_map(static fn ($c): string => $c->regle, $rapport->constatsGlobaux);
        self::assertContains('titres-uniques', $codesGlobaux);
        self::assertContains('robots-txt', $codesGlobaux);
        self::assertContains('sitemap-xml', $codesGlobaux);
    }

    public function testUnRobotsTxtManquantEstUneErreurGlobale(): void
    {
        $reponses = [
            'https://valeurverte.localhost/' => new MockResponse('<title>Accueil valeur verte du bien</title>', ['http_code' => 200]),
            'https://valeurverte.localhost/sitemap.xml' => new MockResponse('<?xml version="1.0"?><urlset></urlset>', ['http_code' => 200]),
            // robots.txt : pas de réponse mappée → 404
        ];

        $rapport = $this->auditeur($reponses, 'https://valeurverte.localhost/')->auditer();

        $robots = array_values(array_filter($rapport->constatsGlobaux, static fn ($c): bool => 'robots-txt' === $c->regle));
        self::assertCount(1, $robots);
        self::assertSame(Gravite::Erreur, $robots[0]->gravite);
    }

    public function testUnSitemapIllisibleEstUneErreurGlobale(): void
    {
        $reponses = [
            'https://valeurverte.localhost/' => new MockResponse('<title>Accueil valeur verte du bien</title>', ['http_code' => 200]),
            'https://valeurverte.localhost/robots.txt' => new MockResponse('User-agent: *', ['http_code' => 200]),
            'https://valeurverte.localhost/sitemap.xml' => new MockResponse('ceci n\'est pas du xml', ['http_code' => 200]),
        ];

        $rapport = $this->auditeur($reponses, 'https://valeurverte.localhost/')->auditer();

        $sitemap = array_values(array_filter($rapport->constatsGlobaux, static fn ($c): bool => 'sitemap-xml' === $c->regle));
        self::assertSame(Gravite::Erreur, $sitemap[0]->gravite);
    }
}
