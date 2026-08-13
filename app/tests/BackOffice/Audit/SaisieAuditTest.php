<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Audit;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Audit\SaisieInvalide;
use App\BackOffice\Rapport\Dpe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaisieAuditTest extends TestCase
{
    public function testUneSaisieDansLesBornesEstAcceptee(): void
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C);

        self::assertSame('12 rue des Lilas, Toulouse', $saisie->adresse());
        self::assertSame(90, $saisie->surfaceBatie());
        self::assertSame(500, $saisie->surfaceTerrain());
        self::assertSame(Dpe::E, $saisie->dpeAvant());
        self::assertSame(Dpe::C, $saisie->dpeApres());
        self::assertNull($saisie->nombrePieces());
    }

    #[DataProvider('surfacesBatiesValides')]
    public function testLesBornesDeSurfaceBatieSontInclusives(int $surface): void
    {
        $saisie = SaisieAudit::depuis('Adresse', $surface, 500, Dpe::E, Dpe::C);

        self::assertSame($surface, $saisie->surfaceBatie());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function surfacesBatiesValides(): iterable
    {
        yield 'borne basse 20' => [20];
        yield 'borne haute 400' => [400];
    }

    #[DataProvider('saisiesHorsBornes')]
    public function testUneSaisieHorsBorneEstBloquee(int $surfaceBatie, int $surfaceTerrain, Dpe $avant, Dpe $apres, string $champAttendu): void
    {
        try {
            SaisieAudit::depuis('Adresse', $surfaceBatie, $surfaceTerrain, $avant, $apres);
            self::fail('La saisie hors borne aurait dû être bloquée.');
        } catch (SaisieInvalide $erreur) {
            self::assertArrayHasKey($champAttendu, $erreur->erreurs());
        }
    }

    /**
     * @return iterable<string, array{int, int, Dpe, Dpe, string}>
     */
    public static function saisiesHorsBornes(): iterable
    {
        yield 'surface bâtie trop petite' => [19, 500, Dpe::E, Dpe::C, 'surfaceBatie'];
        yield 'surface bâtie trop grande' => [401, 500, Dpe::E, Dpe::C, 'surfaceBatie'];
        yield 'terrain négatif' => [90, -1, Dpe::E, Dpe::C, 'surfaceTerrain'];
        yield 'terrain trop grand' => [90, 10001, Dpe::E, Dpe::C, 'surfaceTerrain'];
        yield 'DPE cible moins bon' => [90, 500, Dpe::C, Dpe::E, 'dpeApres'];
        yield 'DPE cible identique' => [90, 500, Dpe::C, Dpe::C, 'dpeApres'];
    }

    public function testUneAdresseVideEstBloquee(): void
    {
        $this->expectException(SaisieInvalide::class);

        SaisieAudit::depuis('   ', 90, 500, Dpe::E, Dpe::C);
    }

    public function testLesBornesDeTerrainSontInclusives(): void
    {
        $sansTerrain = SaisieAudit::depuis('Adresse', 90, 0, Dpe::E, Dpe::C);
        $grandTerrain = SaisieAudit::depuis('Adresse', 90, 10000, Dpe::E, Dpe::C);

        self::assertSame(0, $sansTerrain->surfaceTerrain());
        self::assertSame(10000, $grandTerrain->surfaceTerrain());
    }

    public function testToutesLesErreursSontRemonteesEnUneFois(): void
    {
        try {
            SaisieAudit::depuis('', 5, 99999, Dpe::C, Dpe::E);
            self::fail('La saisie aurait dû être bloquée.');
        } catch (SaisieInvalide $erreur) {
            self::assertSame(
                ['adresse', 'surfaceBatie', 'surfaceTerrain', 'dpeApres'],
                array_keys($erreur->erreurs()),
            );
        }
    }
}
