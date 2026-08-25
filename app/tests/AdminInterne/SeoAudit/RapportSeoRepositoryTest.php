<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\RapportAuditSeo;
use App\AdminInterne\SeoAudit\RapportSeo;
use App\AdminInterne\SeoAudit\RapportSeoRepository;
use App\AdminInterne\SeoAudit\ResultatPage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RapportSeoRepositoryTest extends KernelTestCase
{
    private function repository(): RapportSeoRepository
    {
        self::bootKernel();
        $repository = static::getContainer()->get(RapportSeoRepository::class);
        self::assertInstanceOf(RapportSeoRepository::class, $repository);

        return $repository;
    }

    private function auditExemple(): RapportAuditSeo
    {
        return new RapportAuditSeo(
            [new ResultatPage('https://a.test/', 200, [
                Constat::ok('titre', 'Titre de 30 caractères.'),
                Constat::alerte('canonical', 'Aucune balise canonical.'),
            ])],
            [Constat::erreur('robots-txt', 'robots.txt inaccessible (code HTTP 404).')],
        );
    }

    public function testEnregistreEtRelitUnRapportAvecSonInstantane(): void
    {
        $repository = $this->repository();

        $rapport = RapportSeo::depuis($this->auditExemple(), new \DateTimeImmutable('2026-08-25 04:00:00'));
        $repository->enregistrer($rapport);

        $relu = $repository->dernier();
        self::assertNotNull($relu);
        self::assertSame(1, $relu->nombreUrls());
        self::assertSame(1, $relu->nombreOk());
        self::assertSame(1, $relu->nombreAlerte());
        self::assertSame(1, $relu->nombreErreur());
        self::assertSame($this->auditExemple()->score(), $relu->scoreTechnique());

        // L'instantané JSONB restitue le détail par URL et par règle.
        $instantane = $relu->instantane();
        self::assertSame('https://a.test/', $instantane['pages'][0]['url']);
        self::assertSame('canonical', $instantane['pages'][0]['constats'][1]['regle']);
        self::assertSame('robots-txt', $instantane['globaux'][0]['regle']);
    }

    public function testDernierRendLeRapportLePlusRecent(): void
    {
        $repository = $this->repository();

        $repository->enregistrer(RapportSeo::depuis($this->auditExemple(), new \DateTimeImmutable('2026-08-20 04:00:00')));
        $repository->enregistrer(RapportSeo::depuis($this->auditExemple(), new \DateTimeImmutable('2026-08-24 04:00:00')));

        $dernier = $repository->dernier();
        self::assertNotNull($dernier);
        self::assertEquals(new \DateTimeImmutable('2026-08-24 04:00:00'), $dernier->executeLe());
    }

    public function testTendanceRendLesRapportsDuPlusAncienAuPlusRecent(): void
    {
        $repository = $this->repository();

        $repository->enregistrer(RapportSeo::depuis($this->auditExemple(), new \DateTimeImmutable('2026-08-24 04:00:00')));
        $repository->enregistrer(RapportSeo::depuis($this->auditExemple(), new \DateTimeImmutable('2026-08-20 04:00:00')));

        $tendance = $repository->tendance(10);
        self::assertCount(2, $tendance);
        self::assertEquals(new \DateTimeImmutable('2026-08-20 04:00:00'), $tendance[0]->executeLe());
        self::assertEquals(new \DateTimeImmutable('2026-08-24 04:00:00'), $tendance[1]->executeLe());
    }
}
