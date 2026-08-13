<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Coefficient;

use App\AdminInterne\Coefficient\DiagnosticMaille;
use App\AdminInterne\Coefficient\SeuilsSupervision;
use PHPUnit\Framework\TestCase;

final class DiagnosticMailleTest extends TestCase
{
    public function testUneMailleSaineNeRemonteAucuneRaison(): void
    {
        $diagnostic = DiagnosticMaille::pour(
            erreurMedianePct: 17.0,
            gainEcLog: 0.30,
            alerteBord: null,
            seuils: $this->seuils(),
        );

        self::assertFalse($diagnostic->estAberrante());
        self::assertSame([], $diagnostic->raisons());
    }

    public function testUneErreurMedianeAuDessusDuSeuilEstFlaggee(): void
    {
        $diagnostic = DiagnosticMaille::pour(
            erreurMedianePct: 42.0,
            gainEcLog: 0.30,
            alerteBord: null,
            seuils: $this->seuils(),
        );

        self::assertTrue($diagnostic->estAberrante());
        self::assertContains('erreur_mediane_elevee', $diagnostic->raisons());
    }

    public function testUnGainEcNegatifEstIncoherentDoncFlagge(): void
    {
        $diagnostic = DiagnosticMaille::pour(
            erreurMedianePct: 17.0,
            gainEcLog: -0.05,
            alerteBord: null,
            seuils: $this->seuils(),
        );

        self::assertTrue($diagnostic->estAberrante());
        self::assertContains('gain_ec_incoherent', $diagnostic->raisons());
    }

    public function testUnGainEcHorsBorneEstFlagge(): void
    {
        $diagnostic = DiagnosticMaille::pour(
            erreurMedianePct: 17.0,
            gainEcLog: 0.95,
            alerteBord: null,
            seuils: $this->seuils(),
        );

        self::assertTrue($diagnostic->estAberrante());
        self::assertContains('gain_ec_hors_borne', $diagnostic->raisons());
    }

    public function testUneAlerteDeBordEstReportee(): void
    {
        $diagnostic = DiagnosticMaille::pour(
            erreurMedianePct: 17.0,
            gainEcLog: 0.30,
            alerteBord: 'rupture coef_m2_bati avec 32013',
            seuils: $this->seuils(),
        );

        self::assertTrue($diagnostic->estAberrante());
        self::assertContains('rupture_bord', $diagnostic->raisons());
    }

    private function seuils(): SeuilsSupervision
    {
        return SeuilsSupervision::de(erreurMedianeMaxPct: 30.0, gainEcMaxLog: 0.70);
    }
}
