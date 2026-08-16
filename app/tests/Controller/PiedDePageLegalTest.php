<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Le pied de page public est le seul chemin d'accès aux pages légales (#228) :
 * s'il pointe dans le vide, l'obligation n'est pas remplie.
 */
final class PiedDePageLegalTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesPubliques(): iterable
    {
        yield 'vitrine' => ['/'];
        yield 'mentions légales' => ['/mentions-legales'];
        yield 'confidentialité' => ['/confidentialite'];
        yield 'CGU' => ['/cgu'];
        yield 'CGV' => ['/cgv'];
    }

    /**
     * @dataProvider pagesPubliques
     */
    public function testLePiedDePageMeneAuxQuatrePagesLegales(string $chemin): void
    {
        $crawler = $this->page($chemin);

        $liens = $crawler->filter('footer a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );
        foreach (['/mentions-legales', '/confidentialite', '/cgu', '/cgv'] as $attendu) {
            self::assertContains($attendu, $liens, \sprintf('Le pied de page de « %s » doit mener à %s.', $chemin, $attendu));
        }
    }

    /**
     * @dataProvider pagesPubliques
     */
    public function testLePiedDePageNaAucunLienMort(string $chemin): void
    {
        $crawler = $this->page($chemin);

        $morts = $crawler->filter('footer a')->reduce(
            static fn (Crawler $lien): bool => \in_array(trim((string) $lien->attr('href')), ['', '#'], true),
        );

        self::assertSame(0, $morts->count(), \sprintf('Des liens morts subsistent dans le pied de page de « %s ».', $chemin));
    }

    /**
     * Aucun audit RGAA n'a été mené : afficher la conformité serait une allégation
     * non étayée.
     */
    public function testLaVitrineNAllegueePlusUneConformiteRgaaNonAuditee(): void
    {
        $crawler = $this->page('/');

        self::assertStringNotContainsString('conforme RGAA', $crawler->filter('footer')->text());
    }

    private function page(string $chemin): Crawler
    {
        $client = static::createClient();
        $crawler = $client->request('GET', 'http://'.self::HOTE.$chemin);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
