<?php

declare(strict_types=1);

namespace App\Tests\Sitemap;

use App\Publication\PerimetrePublic;
use App\Sitemap\SitemapGenerator;
use App\Tests\Publication\ProvisionneModelePublie;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : le sitemap (#365) publie les pages commune **indexables**
 * (#361) et les hubs EPCI mesurés, via les providers branchés au générateur. Une
 * commune en repli reste hors sitemap.
 */
final class CommunesEtEpciSitemapTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    private const COEF = '{"D":0.0,"C":0.11,"E":-0.19}';
    private const HOTE = 'https://valeurverte.localhost';

    public function testCommunesIndexablesEtEpciMesuresSontPublies(): void
    {
        self::bootKernel();
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->rattacherCommuneEpci('33063', '243300316');
        $this->semerMaille('33063', coefDpe: self::COEF); // indexable
        $this->semerCommune('33065', 'Bruges', '33');
        $this->semerMaille('33065', coefDpe: self::COEF, poidsPooling: 0.3); // repli
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $generateur = self::getContainer()->get(SitemapGenerator::class);
        self::assertInstanceOf(SitemapGenerator::class, $generateur);
        $urls = $generateur->urls();

        self::assertContains(self::HOTE.'/valeur-verte/gironde/bordeaux', $urls, 'La commune indexable est au sitemap.');
        self::assertContains(self::HOTE.'/valeur-verte/epci/bordeaux-metropole', $urls, 'Le hub EPCI mesuré est au sitemap.');
        self::assertNotContains(self::HOTE.'/valeur-verte/gironde/bruges', $urls, 'La commune en repli (#361) est exclue du sitemap.');
    }

    public function testLeSitemapEstRestreintAuPerimetre(): void
    {
        self::bootKernel();
        // Deux départements mesurés (31 Occitanie, 33 Gironde), une commune et un EPCI.
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->rattacherCommuneEpci('33063', '243300316');
        $this->semerMaille('33063', coefDpe: self::COEF);
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerMaille('31001', coefDpe: self::COEF);
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        // Phase 1 : seul le 31 est ouvert, communes et EPCI fermés.
        self::getContainer()->set(
            PerimetrePublic::class,
            new PerimetrePublic(['31'], communesPubliques: false, epciPublics: false),
        );

        $generateur = self::getContainer()->get(SitemapGenerator::class);
        self::assertInstanceOf(SitemapGenerator::class, $generateur);
        $urls = $generateur->urls();

        self::assertContains(self::HOTE.'/valeur-verte/haute-garonne', $urls, 'Le hub du périmètre est au sitemap.');
        self::assertNotContains(self::HOTE.'/valeur-verte/gironde', $urls, 'Un hub hors périmètre est exclu.');
        self::assertNotContains(self::HOTE.'/valeur-verte/gironde/bordeaux', $urls, 'Les communes sont exclues (type fermé).');
        self::assertNotContains(self::HOTE.'/valeur-verte/epci/bordeaux-metropole', $urls, 'Les EPCI sont exclus (type fermé).');
    }
}
