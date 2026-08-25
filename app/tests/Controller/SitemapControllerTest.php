<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Exposition du `sitemap.xml` des pages publiques indexables (#340) : statut,
 * type de contenu, validité XML, présence des URLs publiques et absence des
 * zones privées / jetons / widget. La cachabilité partagée est vérifiée à part.
 */
final class SitemapControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLeSitemapRepondEnXml(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function testLeSitemapEstUnUrlsetValide(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');
        $xml = (string) $client->getResponse()->getContent();

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml), 'Le corps doit être un XML bien formé.');
        self::assertSame('urlset', $document->documentElement?->localName);
        self::assertSame(
            'http://www.sitemaps.org/schemas/sitemap/0.9',
            $document->documentElement?->namespaceURI,
        );
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

    /**
     * @dataProvider urlsPubliquesAttendues
     */
    public function testLeSitemapListeChaquePagePublique(string $url): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');

        self::assertStringContainsString('<loc>'.$url.'</loc>', (string) $client->getResponse()->getContent());
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

    /**
     * @dataProvider fragmentsInterdits
     */
    public function testLeSitemapNExposeAucuneZonePriveeNiJeton(string $fragment): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');

        self::assertStringNotContainsString($fragment, (string) $client->getResponse()->getContent());
    }

    /**
     * Le sitemap ne dépend d'aucune session et ne change qu'à la publication d'un
     * millésime : il doit rester cachable par un proxy partagé.
     */
    public function testLeSitemapEstCachablePubliquement(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/sitemap.xml');

        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=', $cacheControl);
    }
}
