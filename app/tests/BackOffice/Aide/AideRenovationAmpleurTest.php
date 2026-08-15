<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Aide;

use App\BackOffice\Aide\AideRenovationAmpleur;
use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use PHPUnit\Framework\TestCase;

/**
 * Calcul de l'aide MaPrimeRénov' rénovation d'ampleur (#26) :
 * aide = min(coût HT, plafond(gain de classes)) × taux(catégorie).
 *
 * Quand le pro n'a pas précisé la situation du ménage, on ne devine pas : on sert
 * une fourchette bornée par les taux extrêmes du barème. L'information manquante
 * reste visible au lieu d'être remplacée par un chiffre faux.
 */
final class AideRenovationAmpleurTest extends TestCase
{
    public function testLAideEstLeTauxAppliqueAuCout(): void
    {
        $aide = AideRenovationAmpleur::pour(
            Montant::fromEuros(20000),
            2,
            CategorieRevenu::Modeste,
            $this->bareme(),
        );

        self::assertSame(12000, $aide->euros());
    }

    public function testLeCoutEstEcreteAuPlafondDeDepenses(): void
    {
        // 31 760 € de travaux, gain de 2 classes : plafond 30 000 € HT, taux 60 %.
        $aide = AideRenovationAmpleur::pour(
            Montant::fromEuros(31760),
            2,
            CategorieRevenu::Modeste,
            $this->bareme(),
        );

        self::assertSame(18000, $aide->euros());
    }

    public function testUnGainDeTroisClassesReleveLePlafond(): void
    {
        // Même coût, mais 3 classes gagnées : le plafond passe à 40 000 €, donc
        // 35 000 € ne sont plus écrêtés.
        $aide = AideRenovationAmpleur::pour(
            Montant::fromEuros(35000),
            3,
            CategorieRevenu::Modeste,
            $this->bareme(),
        );

        self::assertSame(21000, $aide->euros());
    }

    public function testAucuneAideQuandLeGainEstInsuffisant(): void
    {
        $aide = AideRenovationAmpleur::pour(
            Montant::fromEuros(20000),
            1,
            CategorieRevenu::Modeste,
            $this->bareme(),
        );

        self::assertSame(0, $aide->euros());
    }

    public function testLaCategorieLaPlusAiseeOuvreQuandMemeUnDroit(): void
    {
        // Le profil « supérieur » est exclu du parcours par geste mais reste
        // éligible à la rénovation d'ampleur, à 10 %.
        $aide = AideRenovationAmpleur::pour(
            Montant::fromEuros(20000),
            2,
            CategorieRevenu::Superieur,
            $this->bareme(),
        );

        self::assertSame(2000, $aide->euros());
    }

    public function testSansCategorieOnSertUneFourchetteBorneeParLesTauxExtremes(): void
    {
        $fourchette = AideRenovationAmpleur::fourchette(
            Montant::fromEuros(31760),
            2,
            $this->bareme(),
        );

        // Écrêté à 30 000 € : de 10 % (supérieurs) à 80 % (très modestes).
        self::assertSame(3000, $fourchette->minimum()->euros());
        self::assertSame(24000, $fourchette->maximum()->euros());
    }

    public function testLaFourchetteEstNulleQuandLeGainEstInsuffisant(): void
    {
        $fourchette = AideRenovationAmpleur::fourchette(
            Montant::fromEuros(31760),
            1,
            $this->bareme(),
        );

        self::assertSame(0, $fourchette->minimum()->euros());
        self::assertSame(0, $fourchette->maximum()->euros());
    }

    private function bareme(): BaremeAides
    {
        return BaremeAides::parDefautNational(
            PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01')),
        );
    }
}
