<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Aide;

use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\BaremeAidesEnregistre;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use PHPUnit\Framework\TestCase;

/**
 * Adaptateur entre le barème d'aides (objet-valeur pur, versionné) et la base (#26).
 * Le domaine ne dépend jamais de Doctrine : l'entité sait seulement se composer
 * depuis un barème et le restituer à l'identique.
 */
final class BaremeAidesEnregistreTest extends TestCase
{
    public function testUnBaremePersisteSeRelitALIdentique(): void
    {
        $bareme = BaremeAides::parDefautNational(
            PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01')),
        );

        $relu = BaremeAidesEnregistre::depuis($bareme)->versBareme();

        self::assertSame(80, $relu->tauxPourCent(CategorieRevenu::TresModeste));
        self::assertSame(10, $relu->tauxPourCent(CategorieRevenu::Superieur));
        self::assertSame(30000, $relu->plafondDepenses(2)->euros());
        self::assertSame(40000, $relu->plafondDepenses(3)->euros());
        self::assertSame(50000, $relu->plafondEcoPtz()->euros());
        self::assertSame(55, $relu->tauxTvaPourMille());
    }

    public function testLaPeriodeOuverteEstConservee(): void
    {
        $bareme = BaremeAides::parDefautNational(
            PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01')),
        );

        $relu = BaremeAidesEnregistre::depuis($bareme)->versBareme();

        self::assertEquals(new \DateTimeImmutable('2026-01-01'), $relu->periode()->valideDu());
        self::assertNull($relu->periode()->valideAu());
    }

    public function testCloturerFermeLaPeriodeSansToucherAuxValeurs(): void
    {
        // Le versionnement ferme une période ; il n'écrase jamais les montants
        // de la version close, sinon les rapports antérieurs deviendraient faux.
        $enregistre = BaremeAidesEnregistre::depuis(BaremeAides::parDefautNational(
            PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01')),
        ));

        $enregistre->cloturerLe(new \DateTimeImmutable('2026-09-01'));

        $relu = $enregistre->versBareme();
        self::assertEquals(new \DateTimeImmutable('2026-09-01'), $relu->periode()->valideAu());
        self::assertSame(80, $relu->tauxPourCent(CategorieRevenu::TresModeste));
        self::assertSame(30000, $relu->plafondDepenses(2)->euros());
    }

    public function testUnBaremeSurMesureSeRelitAvecSesPropresValeurs(): void
    {
        $bareme = BaremeAides::depuis(
            PeriodeValidite::depuis(new \DateTimeImmutable('2026-09-01')),
            [
                CategorieRevenu::TresModeste->name => 70,
                CategorieRevenu::Modeste->name => 50,
                CategorieRevenu::Intermediaire->name => 35,
                CategorieRevenu::Superieur->name => 0,
            ],
            [2 => Montant::fromEuros(25000), 3 => Montant::fromEuros(35000)],
            Montant::fromEuros(40000),
            100,
        );

        $relu = BaremeAidesEnregistre::depuis($bareme)->versBareme();

        self::assertSame(70, $relu->tauxPourCent(CategorieRevenu::TresModeste));
        self::assertSame(0, $relu->tauxPourCent(CategorieRevenu::Superieur));
        self::assertSame(25000, $relu->plafondDepenses(2)->euros());
        self::assertSame(35000, $relu->plafondDepenses(4)->euros());
        self::assertSame(40000, $relu->plafondEcoPtz()->euros());
        self::assertSame(100, $relu->tauxTvaPourMille());
    }
}
