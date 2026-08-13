<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Estimation;

use App\BackOffice\Estimation\ClasseNonPubliee;
use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Rapport\Dpe;
use PHPUnit\Framework\TestCase;

/**
 * La maille expose le contexte de calcul employé (#33) : les coefficients log
 * des deux étiquettes et l'erreur médiane, pour l'archiver dans l'instantané.
 */
final class CoefficientMailleContexteTest extends TestCase
{
    public function testExposeLesCoefficientsEmployesEtLErreurMediane(): void
    {
        $contexte = $this->maille()->contexte(Dpe::E, Dpe::C);

        self::assertSame(-0.20, $contexte->coefDpeAvant());
        self::assertSame(0.10, $contexte->coefDpeApres());
        self::assertSame(17.22, $contexte->erreurMediane());
    }

    public function testUneEtiquetteNonPublieeEstRefusee(): void
    {
        $this->expectException(ClasseNonPubliee::class);

        $this->maille()->contexte(Dpe::F, Dpe::C);
    }

    private function maille(): CoefficientMaille
    {
        return CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: 10,
            n: 134,
            erreurMediane: 17.22,
            coefDpe: ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
        );
    }
}
