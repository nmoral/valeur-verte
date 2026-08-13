<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Estimation;

use App\BackOffice\Estimation\ClasseNonPubliee;
use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\Restitution;
use PHPUnit\Framework\TestCase;

final class CoefficientMailleTest extends TestCase
{
    /**
     * valeur_creee = P × (exp(Δ) − 1), Δ = coef_dpe[après] − coef_dpe[avant].
     * Fourchette = valeur brute × (1 ± erreur_mediane/100).
     */
    public function testElleEstimeLaValeurCreeeEnEurosDepuisLeModeleLog(): void
    {
        $maille = self::maille(['D' => 0.0, 'C' => 0.10, 'E' => -0.20], erreurMediane: 20.0);

        $restitution = $maille->estimer(Dpe::E, Dpe::C, prixBaseEuros: 100000, prixSaisiParLePro: false);

        self::assertInstanceOf(Restitution::class, $restitution);
        self::assertSame(34986, $restitution->montantEuros());
        self::assertSame(27989, $restitution->borneBasseEuros());
        self::assertSame(41983, $restitution->borneHauteEuros());
    }

    public function testLaRestitutionPorteLeContexteDeLaMaille(): void
    {
        $maille = self::maille(
            ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
            erreurMediane: 20.0,
            n: 134,
            rayonKm: 10,
            millesime: 2024,
        );

        $restitution = $maille->estimer(Dpe::E, Dpe::C, 100000, false);

        self::assertSame(134, $restitution->n());
        self::assertSame(10, $restitution->rayonKm());
        self::assertSame(2024, $restitution->millesime());
    }

    public function testUnMemeDpeAvantEtApresDonneUnGainNul(): void
    {
        $maille = self::maille(['D' => 0.0, 'C' => 0.10, 'E' => -0.20], erreurMediane: 20.0);

        $restitution = $maille->estimer(Dpe::C, Dpe::C, 100000, false);

        self::assertSame(0, $restitution->montantEuros());
        self::assertSame(0, $restitution->borneBasseEuros());
        self::assertSame(0, $restitution->borneHauteEuros());
    }

    public function testLeRepliDepartementalSePropageALaRestitution(): void
    {
        $maille = self::maille(['D' => 0.0, 'C' => 0.10], erreurMediane: 20.0, rayonKm: null);

        $restitution = $maille->estimer(Dpe::D, Dpe::C, 100000, false);

        self::assertNull($restitution->rayonKm());
    }

    public function testUneClasseNonPublieeRendLEstimationIndisponible(): void
    {
        $maille = self::maille(['D' => 0.0, 'C' => 0.10], erreurMediane: 20.0);

        $this->expectException(ClasseNonPubliee::class);

        $maille->estimer(Dpe::F, Dpe::C, 100000, false);
    }

    /**
     * @param array<string, float> $coefDpe
     */
    private static function maille(
        array $coefDpe,
        float $erreurMediane,
        int $n = 100,
        ?int $rayonKm = 10,
        int $millesime = 2024,
    ): CoefficientMaille {
        return CoefficientMaille::depuis(
            millesime: $millesime,
            rayonKm: $rayonKm,
            n: $n,
            erreurMediane: $erreurMediane,
            coefDpe: $coefDpe,
        );
    }
}
