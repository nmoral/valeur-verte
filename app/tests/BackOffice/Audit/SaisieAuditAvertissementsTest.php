<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Audit;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Rapport\Dpe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaisieAuditAvertissementsTest extends TestCase
{
    public function testSansNombreDePiecesAucunAvertissement(): void
    {
        $saisie = SaisieAudit::depuis('Adresse', 90, 500, Dpe::E, Dpe::C);

        self::assertSame([], $saisie->avertissements());
    }

    public function testZeroPieceNeDeclencheNiAvertissementNiDivisionParZero(): void
    {
        $saisie = SaisieAudit::depuis('Adresse', 90, 500, Dpe::E, Dpe::C, 0);

        self::assertSame([], $saisie->avertissements());
    }

    #[DataProvider('ratiosPlausibles')]
    public function testUnRatioPlausibleNeDeclencheAucunAvertissement(int $surface, int $pieces): void
    {
        $saisie = SaisieAudit::depuis('Adresse', $surface, 500, Dpe::E, Dpe::C, $pieces);

        self::assertSame([], $saisie->avertissements());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function ratiosPlausibles(): iterable
    {
        yield 'usuel 22 m²/pièce' => [90, 4];
        yield 'borne basse 9 m²/pièce' => [90, 10];
        yield 'borne haute 60 m²/pièce' => [60, 1];
    }

    #[DataProvider('ratiosDouteux')]
    public function testUnRatioDouteuxDeclencheUnAvertissementNonBloquant(int $surface, int $pieces): void
    {
        $saisie = SaisieAudit::depuis('Adresse', $surface, 500, Dpe::E, Dpe::C, $pieces);

        $avertissements = $saisie->avertissements();
        self::assertCount(1, $avertissements);
        self::assertStringContainsString('pièce', $avertissements[0]);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function ratiosDouteux(): iterable
    {
        yield 'trop de pièces (7,5 m²/pièce)' => [90, 12];
        yield 'trop peu de pièces (90 m²/pièce)' => [90, 1];
    }
}
