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
