<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class SeoTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLaHomePorteLesMetaDeReferencement(): void
    {
        $crawler = $this->page('/');

        self::assertGreaterThanOrEqual(70, mb_strlen($this->contenu($crawler, 'meta[name="description"]')));
        self::assertStringContainsString('index', $this->contenu($crawler, 'meta[name="robots"]'));
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertNotSame('', $this->contenu($crawler, 'meta[property="og:title"]'));
        self::assertSame('website', $this->contenu($crawler, 'meta[property="og:type"]'));
        self::assertNotSame('', $this->contenu($crawler, 'meta[name="twitter:card"]'));
    }

    public function testLaHomeExposeDesDonneesStructurees(): void
    {
        $crawler = $this->page('/');

        $jsonLd = $crawler->filter('script[type="application/ld+json"]');
        self::assertGreaterThanOrEqual(1, $jsonLd->count(), 'Des données structurées JSON-LD sont attendues.');
        $donnees = json_decode($jsonLd->first()->text(), true);
        self::assertIsArray($donnees);
        self::assertArrayHasKey('@context', $donnees);
    }

    public function testLaPageDInscriptionPorteLesMeta(): void
    {
        $crawler = $this->page('/inscription');

        self::assertGreaterThanOrEqual(70, mb_strlen($this->contenu($crawler, 'meta[name="description"]')));
        self::assertSame(1, $crawler->filter('link[rel="canonical"]')->count());
        self::assertNotSame('', $this->contenu($crawler, 'meta[property="og:title"]'));
    }

    public function testLaPageDeLoginPorteUneDescriptionEtNEstPasIndexee(): void
    {
        $crawler = $this->page('/login');

        self::assertGreaterThanOrEqual(40, mb_strlen($this->contenu($crawler, 'meta[name="description"]')));
        // Une page d'authentification n'a pas de valeur de référencement.
        self::assertStringContainsString('noindex', $this->contenu($crawler, 'meta[name="robots"]'));
    }

    private function page(string $chemin): Crawler
    {
        $client = static::createClient();
        $crawler = $client->request('GET', 'http://'.self::HOTE.$chemin);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function contenu(Crawler $crawler, string $selecteur): string
    {
        $noeud = $crawler->filter($selecteur);
        self::assertGreaterThan(0, $noeud->count(), \sprintf('Balise attendue : %s', $selecteur));

        return (string) $noeud->attr('content');
    }
}
