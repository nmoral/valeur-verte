<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoPresence\EtatIndexation;
use App\AdminInterne\SeoPresence\MetriquesRecherche;
use App\AdminInterne\SeoPresence\RapportPresence;
use App\AdminInterne\SeoPresence\RapportPresenceExterne;
use App\AdminInterne\SeoPresence\RapportPresenceRepository;
use App\AdminInterne\SeoPresence\RequeteTop;
use App\AdminInterne\SeoPresence\WebVitals;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RapportPresenceRepositoryTest extends KernelTestCase
{
    private function repository(): RapportPresenceRepository
    {
        self::bootKernel();
        $repository = static::getContainer()->get(RapportPresenceRepository::class);
        self::assertInstanceOf(RapportPresenceRepository::class, $repository);

        return $repository;
    }

    private function auditConfigure(): RapportPresenceExterne
    {
        return new RapportPresenceExterne(
            true,
            new MetriquesRecherche(100, 2000, 0.05, 6.5, [new RequeteTop('valeur verte', 80, 1000)]),
            [new EtatIndexation('https://a.test/', 'Submitted and indexed')],
            [new WebVitals('https://a.test/', 2100, 0.05, 180, 92)],
        );
    }

    public function testEnregistreEtRelitUnRapportConfigure(): void
    {
        $repository = $this->repository();

        $repository->enregistrer(RapportPresence::depuis($this->auditConfigure(), new \DateTimeImmutable('2026-08-25 01:00:00')));

        $relu = $repository->dernier();
        self::assertNotNull($relu);
        self::assertTrue($relu->estConfigure());
        self::assertSame(100, $relu->clics());
        self::assertSame(2000, $relu->impressions());
        self::assertSame(6.5, $relu->positionMoyenne());
        self::assertSame(1, $relu->nombreUrls());

        $instantane = $relu->instantane();
        self::assertIsArray($instantane['recherche']);
        self::assertSame('valeur verte', $instantane['recherche']['topRequetes'][0]['requete']);
        self::assertSame('https://a.test/', $instantane['vitals'][0]['url']);
        self::assertSame(2100, $instantane['vitals'][0]['lcpMs']);
        self::assertSame('Submitted and indexed', $instantane['indexation'][0]['couverture']);
    }

    public function testUnRapportNonConfigureNAPasDeMetriques(): void
    {
        $repository = $this->repository();

        $repository->enregistrer(RapportPresence::depuis(new RapportPresenceExterne(false, null, [], []), new \DateTimeImmutable('2026-08-25 01:00:00')));

        $relu = $repository->dernier();
        self::assertNotNull($relu);
        self::assertFalse($relu->estConfigure());
        self::assertNull($relu->positionMoyenne());
        self::assertSame(0, $relu->clics());
        self::assertSame(0, $relu->nombreUrls());
    }

    public function testTendanceDuPlusAncienAuPlusRecent(): void
    {
        $repository = $this->repository();

        $repository->enregistrer(RapportPresence::depuis($this->auditConfigure(), new \DateTimeImmutable('2026-08-24 01:00:00')));
        $repository->enregistrer(RapportPresence::depuis($this->auditConfigure(), new \DateTimeImmutable('2026-08-18 01:00:00')));

        $tendance = $repository->tendance(10);
        self::assertCount(2, $tendance);
        self::assertEquals(new \DateTimeImmutable('2026-08-18 01:00:00'), $tendance[0]->executeLe());
        self::assertEquals(new \DateTimeImmutable('2026-08-24 01:00:00'), $tendance[1]->executeLe());
    }
}
