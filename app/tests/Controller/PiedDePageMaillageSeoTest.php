<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Le pied de page unifié porte le maillage interne SEO (#337) : depuis n'importe
 * quelle page publique, il relie les pages piliers (calculateur, carte nationale,
 * étude par département) pour diffuser l'autorité. On l'éprouve sur une page
 * légale, servie sans aucune donnée : le footer y est identique à celui des pages
 * SEO, sans dépendre d'un millésime publié.
 */
final class PiedDePageMaillageSeoTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLePiedDePageMailleLesPagesPiliersSeo(): void
    {
        $liens = $this->liensDuPied('/mentions-legales');

        foreach (['/', '/calculateur', '/carte-valeur-verte-france', '/valeur-verte-par-departement'] as $attendu) {
            self::assertContains($attendu, $liens, \sprintf('Le pied de page doit mailler la page pilier %s.', $attendu));
        }
    }

    /**
     * @return list<string>
     */
    private function liensDuPied(string $chemin): array
    {
        $client = static::createClient();
        $crawler = $client->request('GET', 'http://'.self::HOTE.$chemin);
        self::assertResponseIsSuccessful();

        return $crawler->filter('footer a')->each(
            static fn (Crawler $lien): string => (string) parse_url((string) $lien->attr('href'), \PHP_URL_PATH),
        );
    }
}
