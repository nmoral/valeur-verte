<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\GrilleCout;

use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\GrilleCoutRepository;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Persistance de la grille de coûts (#34). Tant que le pro n'a rien configuré, la
 * grille nationale par défaut est servie (ONB-3), puis sa grille prend le relais.
 */
final class GrilleCoutRepositoryTest extends KernelTestCase
{
    private GrilleCoutRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(GrilleCoutRepository::class);
        self::assertInstanceOf(GrilleCoutRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testSansConfigurationLaGrilleNationaleEstServie(): void
    {
        $grille = $this->repository->courant();

        self::assertSame(120, $grille->montant(Poste::Murs)->euros());
        self::assertSame(14000, $grille->montant(Poste::Chauffage)->euros());
    }

    public function testUneGrilleEnregistreePrendLeRelais(): void
    {
        $this->repository->provisionner(778001, $this->grillePersonnalisee(999));

        $grille = $this->repository->courant();

        self::assertSame(999, $grille->montant(Poste::Murs)->euros());
    }

    private function grillePersonnalisee(int $euros): GrilleCout
    {
        $montants = [];
        foreach (Poste::cases() as $poste) {
            $montants[$poste->name] = Montant::fromEuros($euros);
        }

        return GrilleCout::depuis(PeriodeValidite::depuis(new \DateTimeImmutable('2026-02-01')), $montants);
    }
}
