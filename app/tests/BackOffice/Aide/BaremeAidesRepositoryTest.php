<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Aide;

use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\BaremeAidesRepository;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Persistance versionnée du barème d'aides (#26). Le barème est **national** :
 * il est maintenu par nous et non cloisonné par tenant, contrairement à la grille
 * de coûts du pro (#34).
 *
 * Publier une nouvelle version clôture la précédente à la date d'effet, sans jamais
 * écraser ses valeurs (§7 — aucun `UPDATE` destructif). C'est la condition pour
 * qu'un rapport émis sous l'ancien barème reste reproductible.
 */
final class BaremeAidesRepositoryTest extends KernelTestCase
{
    private BaremeAidesRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(BaremeAidesRepository::class);
        self::assertInstanceOf(BaremeAidesRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testSansPublicationLeBaremeNationalParDefautEstServi(): void
    {
        $bareme = $this->repository->courant();

        self::assertSame(80, $bareme->tauxPourCent(CategorieRevenu::TresModeste));
        self::assertSame(30000, $bareme->plafondDepenses(2)->euros());
    }

    public function testUnBaremePublieePrendLeRelais(): void
    {
        $this->repository->publier($this->bareme('2026-02-01', tresModeste: 70));

        self::assertSame(70, $this->repository->courant()->tauxPourCent(CategorieRevenu::TresModeste));
    }

    public function testPublierUneNouvelleVersionClotureLaPrecedente(): void
    {
        $this->repository->publier($this->bareme('2026-02-01', tresModeste: 70));

        $this->repository->publier($this->bareme('2026-09-01', tresModeste: 65));

        $ancien = $this->repository->enVigueurLe(new \DateTimeImmutable('2026-05-01'));
        self::assertEquals(new \DateTimeImmutable('2026-09-01'), $ancien->periode()->valideAu());
    }

    public function testLaVersionCloturaeeConserveSesValeurs(): void
    {
        // Le cœur de l'invariant : un rapport émis en mai doit continuer à lire 70 %,
        // même après la publication d'un barème à 65 % en septembre.
        $this->repository->publier($this->bareme('2026-02-01', tresModeste: 70));
        $this->repository->publier($this->bareme('2026-09-01', tresModeste: 65));

        $ancien = $this->repository->enVigueurLe(new \DateTimeImmutable('2026-05-01'));

        self::assertSame(70, $ancien->tauxPourCent(CategorieRevenu::TresModeste));
    }

    public function testChaqueDateEstServieParLaVersionQuiLaCouvre(): void
    {
        $this->repository->publier($this->bareme('2026-02-01', tresModeste: 70));
        $this->repository->publier($this->bareme('2026-09-01', tresModeste: 65));

        self::assertSame(70, $this->repository->enVigueurLe(new \DateTimeImmutable('2026-08-31'))->tauxPourCent(CategorieRevenu::TresModeste));
        self::assertSame(65, $this->repository->enVigueurLe(new \DateTimeImmutable('2026-09-01'))->tauxPourCent(CategorieRevenu::TresModeste));
    }

    public function testAvantToutePublicationLeDefautNationalEstServi(): void
    {
        $this->repository->publier($this->bareme('2026-02-01', tresModeste: 70));

        $bareme = $this->repository->enVigueurLe(new \DateTimeImmutable('2026-01-01'));

        self::assertSame(80, $bareme->tauxPourCent(CategorieRevenu::TresModeste));
    }

    public function testUneDateDEffetAnterieureALaVersionCouranteEstRefusee(): void
    {
        // Rétro-dater réécrirait l'histoire : les rapports déjà émis sous la version
        // courante changeraient de reste à charge.
        $this->repository->publier($this->bareme('2026-09-01', tresModeste: 70));

        $this->expectException(\InvalidArgumentException::class);

        $this->repository->publier($this->bareme('2026-02-01', tresModeste: 65));
    }

    private function bareme(string $dateEffet, int $tresModeste): BaremeAides
    {
        return BaremeAides::depuis(
            PeriodeValidite::depuis(new \DateTimeImmutable($dateEffet)),
            [
                CategorieRevenu::TresModeste->name => $tresModeste,
                CategorieRevenu::Modeste->name => 60,
                CategorieRevenu::Intermediaire->name => 45,
                CategorieRevenu::Superieur->name => 10,
            ],
            [2 => Montant::fromEuros(30000), 3 => Montant::fromEuros(40000)],
            Montant::fromEuros(50000),
            55,
        );
    }
}
