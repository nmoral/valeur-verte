<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Publication\LectureModelePublie;
use App\Publication\Publication;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Exposition du sitemap des pages publiques indexables (#340, #365). ``/sitemap.xml``
 * est désormais un **sitemap index** paginé (≤ 50 000 URLs par fichier enfant,
 * `/sitemap-{n}.xml`) : ~34 746 communes dépassent la limite d'un fichier unique.
 * On vérifie la validité de l'index et des enfants, la présence des pages publiques
 * (agrégées sur les enfants), l'absence des zones privées/jetons, et le cache.
 */
final class SitemapControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLIndexRepondEnXml(): void
    {
        $this->naviguer()->request('GET', 'http://'.self::HOTE.'/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function testLaRacineEstUnSitemapindexValide(): void
    {
        $client = $this->naviguer();
        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');
        $xml = (string) $client->getResponse()->getContent();

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml), 'Le corps doit être un XML bien formé.');
        $racine = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $racine);
        self::assertSame('sitemapindex', $racine->localName);
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $racine->namespaceURI);
        self::assertGreaterThanOrEqual(1, $document->getElementsByTagName('loc')->length);
        // Chaque enfant pointe vers un /sitemap-{n}.xml.
        foreach ($this->cheminsEnfants($xml) as $chemin) {
            self::assertMatchesRegularExpression('#^/sitemap-\d+\.xml$#', $chemin);
        }
    }

    public function testChaqueEnfantEstUnUrlsetPaginéSousLaLimite(): void
    {
        $client = $this->naviguer();
        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');
        $enfants = $this->cheminsEnfants((string) $client->getResponse()->getContent());

        $max = self::getContainer()->getParameter('app.sitemap.max_urls');
        self::assertIsInt($max);

        foreach ($enfants as $chemin) {
            $client->request('GET', 'http://'.self::HOTE.$chemin);
            self::assertResponseIsSuccessful();
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML((string) $client->getResponse()->getContent()));
            $racine = $document->documentElement;
            self::assertInstanceOf(\DOMElement::class, $racine);
            self::assertSame('urlset', $racine->localName);
            self::assertLessThanOrEqual($max, $document->getElementsByTagName('loc')->length);
        }
    }

    #[DataProvider('urlsPubliquesAttendues')]
    public function testChaquePagePubliqueFigureDansUnEnfant(string $url): void
    {
        self::assertContains($url, $this->toutesLesUrls($this->naviguer()));
    }

    #[DataProvider('fragmentsInterdits')]
    public function testAucuneZonePriveeNiJeton(string $fragment): void
    {
        foreach ($this->toutesLesUrls($this->naviguer()) as $url) {
            self::assertStringNotContainsString($fragment, $url);
        }
    }

    public function testLIndexEstCachablePubliquement(): void
    {
        $client = $this->naviguer();
        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');

        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=', $cacheControl);
    }

    public function testUnEnfantHorsBornesEst404(): void
    {
        $this->naviguer()->request('GET', 'http://'.self::HOTE.'/sitemap-9999.xml');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsPubliquesAttendues(): iterable
    {
        yield 'accueil' => ['https://'.self::HOTE.'/'];
        yield 'mentions légales' => ['https://'.self::HOTE.'/mentions-legales'];
        yield 'CGU' => ['https://'.self::HOTE.'/cgu'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fragmentsInterdits(): iterable
    {
        yield 'déconnexion' => ['/logout'];
        // Vitrine restreinte (#385) : la création de compte est masquée, elle ne
        // doit plus être proposée aux robots.
        yield 'inscription' => ['/inscription'];
        yield 'espace pro' => ['://app.'];
        yield 'admin interne' => ['://admin.'];
        yield 'widget' => ['://widget.'];
    }

    /**
     * Agrège les URLs de tous les sitemaps enfants pointés par l'index.
     *
     * @return list<string>
     */
    private function toutesLesUrls(KernelBrowser $client): array
    {
        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');
        $urls = [];
        foreach ($this->cheminsEnfants((string) $client->getResponse()->getContent()) as $chemin) {
            $client->request('GET', 'http://'.self::HOTE.$chemin);
            $document = new \DOMDocument();
            $document->loadXML((string) $client->getResponse()->getContent());
            foreach ($document->getElementsByTagName('loc') as $loc) {
                $urls[] = $loc->textContent;
            }
        }

        return $urls;
    }

    /**
     * @return list<string> les chemins (path) des sitemaps enfants listés par l'index
     */
    private function cheminsEnfants(string $xmlIndex): array
    {
        $document = new \DOMDocument();
        $document->loadXML($xmlIndex);
        $chemins = [];
        foreach ($document->getElementsByTagName('loc') as $loc) {
            $chemins[] = (string) parse_url($loc->textContent, \PHP_URL_PATH);
        }

        return $chemins;
    }

    private function naviguer(?Publication $publication = null): KernelBrowser
    {
        $client = static::createClient();
        // Le kernel ne doit pas se reconstruire entre l'index et les enfants : le
        // stub de publication (et le cache) vivent dans le conteneur courant.
        $client->disableReboot();
        $client->getContainer()->set(LectureModelePublie::class, $this->modele($publication));

        return $client;
    }

    private function modele(?Publication $publication): LectureModelePublie
    {
        return new class($publication) implements LectureModelePublie {
            public function __construct(private ?Publication $publication)
            {
            }

            public function publication(): ?Publication
            {
                return $this->publication;
            }

            public function mailles(): array
            {
                return [];
            }

            public function communesReferencees(): int
            {
                return 0;
            }
        };
    }
}
