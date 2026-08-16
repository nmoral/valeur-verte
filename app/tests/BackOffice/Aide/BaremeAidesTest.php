<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Aide;

use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use PHPUnit\Framework\TestCase;

/**
 * Barème d'aides versionné (#26), calé sur MaPrimeRénov' **rénovation d'ampleur** :
 * un taux par catégorie de revenus appliqué à un plafond de dépenses HT qui dépend
 * du gain de classes DPE. C'est le dispositif qui correspond au positionnement du
 * produit — le marché rémunère le franchissement de seuil (#27), pas le geste isolé.
 *
 * Le parcours « par geste » n'est volontairement pas modélisé : l'isolation des murs
 * en est sortie au 01/01/2026 et un projet de décret (CNH du 02/07/2026, non encore
 * publié au JO) en retire combles, fenêtres, VMC et solaire thermique.
 *
 * Les taux **et** les plafonds sont versionnés : les figer en dur rendrait les
 * rapports non reproductibles au premier changement de barème (§9.1, décision D4).
 */
final class BaremeAidesTest extends TestCase
{
    public function testLeBaremeSertUnTauxDePriseEnChargeParCategorie(): void
    {
        $bareme = BaremeAides::parDefautNational($this->periode());

        self::assertSame(80, $bareme->tauxPourCent(CategorieRevenu::TresModeste));
        self::assertSame(60, $bareme->tauxPourCent(CategorieRevenu::Modeste));
        self::assertSame(45, $bareme->tauxPourCent(CategorieRevenu::Intermediaire));
        self::assertSame(10, $bareme->tauxPourCent(CategorieRevenu::Superieur));
    }

    public function testLeTauxNeCroitJamaisQuandLeRevenuAugmente(): void
    {
        $bareme = BaremeAides::parDefautNational($this->periode());
        $ordre = [
            CategorieRevenu::TresModeste,
            CategorieRevenu::Modeste,
            CategorieRevenu::Intermediaire,
            CategorieRevenu::Superieur,
        ];

        $precedent = null;
        foreach ($ordre as $categorie) {
            $taux = $bareme->tauxPourCent($categorie);
            if (null !== $precedent) {
                self::assertLessThanOrEqual($precedent, $taux);
            }
            $precedent = $taux;
        }
    }

    public function testDemanderLeTauxDUneCategorieNonPreciseeEstRefuse(): void
    {
        // « Non précisée » n'est pas une tranche : le reste à charge en tire une
        // fourchette, le barème ne l'arbitre pas à sa place.
        $bareme = BaremeAides::parDefautNational($this->periode());

        $this->expectException(\InvalidArgumentException::class);

        $bareme->tauxPourCent(CategorieRevenu::NonPrecisee);
    }

    public function testLePlafondDeDepensesDependDuGainDeClasses(): void
    {
        $bareme = BaremeAides::parDefautNational($this->periode());

        self::assertSame(30000, $bareme->plafondDepenses(2)->euros());
        self::assertSame(40000, $bareme->plafondDepenses(3)->euros());
    }

    public function testAuDelaDeTroisClassesLePlafondNeMonteplus(): void
    {
        $bareme = BaremeAides::parDefautNational($this->periode());

        self::assertSame(40000, $bareme->plafondDepenses(5)->euros());
    }

    public function testUnGainInferieurADeuxClassesNouvrePasDroit(): void
    {
        $bareme = BaremeAides::parDefautNational($this->periode());

        self::assertFalse($bareme->ouvreDroit(1));
        self::assertTrue($bareme->ouvreDroit(2));
    }

    public function testDemanderLePlafondDUnGainInsuffisantEstRefuse(): void
    {
        $bareme = BaremeAides::parDefautNational($this->periode());

        $this->expectException(\InvalidArgumentException::class);

        $bareme->plafondDepenses(1);
    }

    public function testLePlafondEcoPtzEtLaTvaNeDependentPasDesRevenus(): void
    {
        $bareme = BaremeAides::parDefautNational($this->periode());

        self::assertSame(50000, $bareme->plafondEcoPtz()->euros());
        // TVA en pour mille : 55 ‰ = 5,5 %, pour rester en entiers comme Montant.
        self::assertSame(55, $bareme->tauxTvaPourMille());
    }

    public function testUnTauxHorsDeLIntervalleZeroCentEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BaremeAides::depuis(
            $this->periode(),
            [
                CategorieRevenu::TresModeste->name => 120,
                CategorieRevenu::Modeste->name => 60,
                CategorieRevenu::Intermediaire->name => 45,
                CategorieRevenu::Superieur->name => 10,
            ],
            [2 => Montant::fromEuros(30000), 3 => Montant::fromEuros(40000)],
            Montant::fromEuros(50000),
            55,
        );
    }

    public function testUnBaremeAmputeDUneCategorieEstRejete(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BaremeAides::depuis(
            $this->periode(),
            [CategorieRevenu::TresModeste->name => 80],
            [2 => Montant::fromEuros(30000), 3 => Montant::fromEuros(40000)],
            Montant::fromEuros(50000),
            55,
        );
    }

    public function testUnBaremePorteSaPeriodeDeValidite(): void
    {
        $periode = $this->periode();

        $bareme = BaremeAides::parDefautNational($periode);

        self::assertSame($periode, $bareme->periode());
    }

    private function periode(): PeriodeValidite
    {
        return PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'));
    }
}
