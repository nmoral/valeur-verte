<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Publication\LectureModelePublie;
use App\Publication\Publication;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Exposition du `sitemap.xml` des pages publiques indexables (#340) : statut,
 * type de contenu, validité XML, présence des URLs publiques et absence des
 * zones privées / jetons / widget. L'état « millésime publié » est stubé — la
 * conditionnalité de la ligne publication a son test dédié côté générateur.
 */
final class SitemapControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLeSitemapRepondEnXml(): void
    {
        $this->sitemap();

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function testLeSitemapEstUnUrlsetValide(): void
    {
        $xml = (string) $this->sitemap()->getResponse()->getContent();

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml), 'Le corps doit être un XML bien formé.');

        $racine = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $racine);
        self::assertSame('urlset', $racine->localName);
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $racine->namespaceURI);
        self::assertGreaterThanOrEqual(1, $document->getElementsByTagName('loc')->length);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsPubliquesAttendues(): iterable
    {
        yield 'accueil' => ['https://'.self::HOTE.'/'];
        yield 'mentions légales' => ['https://'.self::HOTE.'/mentions-legales'];
        yield 'confidentialité' => ['https://'.self::HOTE.'/confidentialite'];
        yield 'CGU' => ['https://'.self::HOTE.'/cgu'];
        yield 'CGV' => ['https://'.self::HOTE.'/cgv'];
        yield 'connexion' => ['https://'.self::HOTE.'/login'];
        yield 'inscription' => ['https://'.self::HOTE.'/inscription'];
    }

    #[DataProvider('urlsPubliquesAttendues')]
    public function testLeSitemapListeChaquePagePublique(string $url): void
    {
        $xml = (string) $this->sitemap()->getResponse()->getContent();

        self::assertStringContainsString('<loc>'.$url.'</loc>', $xml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fragmentsInterdits(): iterable
    {
        yield 'déconnexion' => ['/logout'];
        yield 'vérification email' => ['/verification-email'];
        yield 'désabonnement' => ['/desabonnement'];
        yield 'reset mot de passe' => ['/mot-de-passe-oublie'];
        yield 'espace pro' => ['://app.'];
        yield 'admin interne' => ['://admin.'];
        yield 'widget' => ['://widget.'];
    }

    #[DataProvider('fragmentsInterdits')]
    public function testLeSitemapNExposeAucuneZonePriveeNiJeton(string $fragment): void
    {
        $xml = (string) $this->sitemap()->getResponse()->getContent();

        self::assertStringNotContainsString($fragment, $xml);
    }

    /**
     * Le sitemap ne dépend d'aucune session et ne change qu'à la publication d'un
     * millésime : il doit rester cachable par un proxy partagé.
     */
    public function testLeSitemapEstCachablePubliquement(): void
    {
        $cacheControl = (string) $this->sitemap()->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=', $cacheControl);
    }

    private function sitemap(?Publication $publication = null): KernelBrowser
    {
        $client = static::createClient();
        $client->getContainer()->set(LectureModelePublie::class, $this->modele($publication));

        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');

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
