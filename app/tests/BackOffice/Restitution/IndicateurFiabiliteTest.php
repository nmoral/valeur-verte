<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Restitution\IndicateurFiabilite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndicateurFiabiliteTest extends TestCase
{
    /**
     * Cascade §9.7 : Fragile prime (n < 50 ou repli départemental),
     * puis Solide (n ≥ 80 et rayon ≤ 20 km), sinon Correct.
     * Le repli départemental est porté par un rayon absent (null).
     */
    #[DataProvider('cas')]
    public function testLaFiabiliteDecouleDuCoupleEffectifRayon(int $n, ?int $rayonKm, IndicateurFiabilite $attendu): void
    {
        self::assertSame($attendu, IndicateurFiabilite::depuis($n, $rayonKm));
    }

    /**
     * @return iterable<string, array{int, int|null, IndicateurFiabilite}>
     */
    public static function cas(): iterable
    {
        // 🟢 Solide : n ≥ 80 ET rayon ≤ 20 km (bornes incluses).
        yield 'solide aux bornes' => [80, 20, IndicateurFiabilite::Solide];
        yield 'solide large échantillon proche' => [476, 10, IndicateurFiabilite::Solide];

        // 🟠 Correct : ni fragile ni solide.
        yield 'correct car rayon au-delà de 20' => [80, 21, IndicateurFiabilite::Correct];
        yield 'correct car effectif sous 80' => [79, 15, IndicateurFiabilite::Correct];
        yield 'correct à la borne basse d\'effectif' => [50, 20, IndicateurFiabilite::Correct];
        yield 'correct rayon large effectif suffisant' => [120, 50, IndicateurFiabilite::Correct];

        // 🔴 Fragile : n < 50 (prioritaire) ou repli départemental.
        yield 'fragile car effectif sous 50' => [49, 10, IndicateurFiabilite::Fragile];
        yield 'fragile effectif nul' => [0, 10, IndicateurFiabilite::Fragile];
        yield 'fragile repli départemental malgré grand effectif' => [300, null, IndicateurFiabilite::Fragile];
        yield 'fragile repli départemental et faible effectif' => [40, null, IndicateurFiabilite::Fragile];
    }
}
