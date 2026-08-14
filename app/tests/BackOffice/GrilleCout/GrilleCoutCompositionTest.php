<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\GrilleCout;

use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use PHPUnit\Framework\TestCase;

/**
 * Composition d'une grille à partir de montants explicites (#34) : nécessaire pour
 * relire une grille persistée. Une grille couvre toujours les sept postes (§9.2).
 */
final class GrilleCoutCompositionTest extends TestCase
{
    public function testElleSeComposeDeSesMontantsParPoste(): void
    {
        $periode = PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'));
        $montants = [];
        foreach (Poste::cases() as $poste) {
            $montants[$poste->name] = Montant::fromEuros(100);
        }

        $grille = GrilleCout::depuis($periode, $montants);

        self::assertSame(100, $grille->montant(Poste::Murs)->euros());
        self::assertSame(100, $grille->montant(Poste::Vmc)->euros());
        self::assertSame($periode, $grille->periode());
    }

    public function testUneGrilleAmputeeDUnPosteEstRejetee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GrilleCout::depuis(
            PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01')),
            [Poste::Murs->name => Montant::fromEuros(100)],
        );
    }
}
