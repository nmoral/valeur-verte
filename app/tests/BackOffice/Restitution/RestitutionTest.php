<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Restitution\IndicateurFiabilite;
use App\BackOffice\Restitution\Restitution;
use PHPUnit\Framework\TestCase;

final class RestitutionTest extends TestCase
{
    public function testEllePorteLeContexteNonNegociable(): void
    {
        $restitution = Restitution::depuis(
            montantEuros: 38000,
            borneBasseEuros: 29000,
            borneHauteEuros: 47000,
            n: 476,
            rayonKm: 25,
            millesime: 2024,
        );

        self::assertSame(38000, $restitution->montantEuros());
        self::assertSame(29000, $restitution->borneBasseEuros());
        self::assertSame(47000, $restitution->borneHauteEuros());
        self::assertSame(476, $restitution->n());
        self::assertSame(25, $restitution->rayonKm());
        self::assertSame(2024, $restitution->millesime());
    }

    public function testLaFiabiliteEstDeriveeDeLEffectifEtDuRayon(): void
    {
        $solide = Restitution::depuis(38000, 29000, 47000, 476, 25, 2024);
        $fragile = Restitution::depuis(12000, 6000, 18000, 300, null, 2024);

        self::assertSame(IndicateurFiabilite::Correct, $solide->fiabilite());
        self::assertSame(IndicateurFiabilite::Fragile, $fragile->fiabilite());
    }

    public function testLaPhraseSuitLeFormatCible(): void
    {
        $restitution = Restitution::depuis(38000, 29000, 47000, 476, 25, 2024);

        self::assertSame(
            '+38 000 € (fourchette 29 000 – 47 000 €, sur 476 transactions dans un rayon de 25 km)',
            $restitution->phrase(),
        );
    }

    public function testLaPhraseSignaleLeRepliDepartemental(): void
    {
        $restitution = Restitution::depuis(12000, 6000, 18000, 45, null, 2024);

        self::assertSame(
            '+12 000 € (fourchette 6 000 – 18 000 €, sur 45 transactions au niveau départemental)',
            $restitution->phrase(),
        );
    }
}
