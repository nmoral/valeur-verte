<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Rapport\Dpe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DpeTest extends TestCase
{
    public function testUneLettreProcheDeAEstMeilleure(): void
    {
        self::assertTrue(Dpe::C->estMeilleurQue(Dpe::E));
        self::assertTrue(Dpe::A->estMeilleurQue(Dpe::G));
        self::assertTrue(Dpe::F->estMeilleurQue(Dpe::G));
    }

    public function testUneLettrePlusEloigneeDeANEstPasMeilleure(): void
    {
        self::assertFalse(Dpe::E->estMeilleurQue(Dpe::C));
        self::assertFalse(Dpe::G->estMeilleurQue(Dpe::A));
    }

    public function testUnDpeIdentiqueNEstPasMeilleur(): void
    {
        self::assertFalse(Dpe::C->estMeilleurQue(Dpe::C));
    }

    public function testLeGainSeCompteEnClassesFranchies(): void
    {
        // Entrée du barème d'aides (#26) : la rénovation d'ampleur se dimensionne
        // sur le nombre de classes gagnées, pas sur la liste des gestes.
        self::assertSame(2, Dpe::E->classesGagneesVers(Dpe::C));
        self::assertSame(4, Dpe::G->classesGagneesVers(Dpe::C));
    }

    public function testUnDpeInchangeNeGagneAucuneClasse(): void
    {
        self::assertSame(0, Dpe::C->classesGagneesVers(Dpe::C));
    }

    public function testUneDegradationCompteNegativement(): void
    {
        // §9.8 borne déjà la saisie (cible meilleure que l'actuel) : on ne masque
        // pas le cas, on le rend visible plutôt que de le confondre avec un gain nul.
        self::assertSame(-2, Dpe::C->classesGagneesVers(Dpe::E));
    }

    #[DataProvider('paires')]
    public function testLOrdreSuitLAlphabet(Dpe $meilleur, Dpe $moins): void
    {
        self::assertTrue($meilleur->estMeilleurQue($moins));
        self::assertFalse($moins->estMeilleurQue($meilleur));
    }

    /**
     * @return iterable<string, array{Dpe, Dpe}>
     */
    public static function paires(): iterable
    {
        yield 'A>B' => [Dpe::A, Dpe::B];
        yield 'B>C' => [Dpe::B, Dpe::C];
        yield 'C>D' => [Dpe::C, Dpe::D];
        yield 'D>E' => [Dpe::D, Dpe::E];
        yield 'E>F' => [Dpe::E, Dpe::F];
        yield 'F>G' => [Dpe::F, Dpe::G];
    }
}
