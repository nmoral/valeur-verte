<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\GrilleCout;

use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrilleCoutParDefautTest extends TestCase
{
    public function testLaGrilleParDefautCouvreLesSeptPostes(): void
    {
        $grille = GrilleCout::parDefautNationale($this->creation());

        self::assertEqualsCanonicalizing(Poste::cases(), $grille->postes());
    }

    public function testTousLesMontantsParDefautSontStrictementPositifs(): void
    {
        // Invariant produit : un pro devant une grille vide ne la remplit jamais.
        $grille = GrilleCout::parDefautNationale($this->creation());

        foreach (Poste::cases() as $poste) {
            self::assertGreaterThan(0, $grille->montant($poste)->euros(), $poste->name);
        }
    }

    #[DataProvider('baremeNational')]
    public function testChaquePosteEstPreRempliAvecLaValeurNationale(Poste $poste, int $eurosAttendus): void
    {
        $grille = GrilleCout::parDefautNationale($this->creation());

        self::assertSame($eurosAttendus, $grille->montant($poste)->euros());
    }

    public function testLaGrilleParDefautPorteLaPeriodeDeValiditeFournie(): void
    {
        $periode = $this->creation();

        $grille = GrilleCout::parDefautNationale($periode);

        self::assertSame($periode, $grille->periode());
    }

    /**
     * @return iterable<string, array{Poste, int}>
     */
    public static function baremeNational(): iterable
    {
        yield 'isolation des murs' => [Poste::Murs, 120];
        yield 'isolation des combles' => [Poste::Combles, 45];
        yield 'planchers bas' => [Poste::PlanchersBas, 60];
        yield 'menuiseries' => [Poste::Menuiseries, 720];
        yield 'pompe à chaleur air/eau' => [Poste::Chauffage, 14000];
        yield 'eau chaude sanitaire' => [Poste::Ecs, 3500];
        yield 'VMC double flux' => [Poste::Vmc, 5200];
    }

    private function creation(): PeriodeValidite
    {
        return PeriodeValidite::depuis(new \DateTimeImmutable('2026-08-12 09:00:00'));
    }
}
