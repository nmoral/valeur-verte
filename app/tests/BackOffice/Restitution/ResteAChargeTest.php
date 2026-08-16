<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\Chiffrage\QuantitesTravaux;
use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\ResteACharge;
use PHPUnit\Framework\TestCase;

/**
 * Reste à charge (#26) : coût des travaux HT − aide estimée.
 *
 * Quand la situation du ménage n'est pas déclarée, on ne devine pas : le reste à
 * charge est une fourchette bornée par les taux extrêmes du barème. Le reste
 * **minimum** correspond à l'aide **maximum** — inversion facile à rater.
 */
final class ResteAChargeTest extends TestCase
{
    public function testAvecUneCategorieLAideEtLeResteSontDesMontantsUniques(): void
    {
        // 31 760 € de travaux, gain de 2 classes : écrêté à 30 000 €, taux 60 %.
        $reste = ResteACharge::calculer($this->chiffrage(), 2, CategorieRevenu::Modeste, $this->bareme());

        self::assertFalse($reste->estUneFourchette());
        self::assertSame(31760, $reste->coutHt()->euros());
        self::assertSame(18000, $reste->aideMinimum()->euros());
        self::assertSame(18000, $reste->aideMaximum()->euros());
        self::assertSame(13760, $reste->minimum()->euros());
        self::assertSame(13760, $reste->maximum()->euros());
    }

    public function testSansCategorieLeResteEstUneFourchette(): void
    {
        $reste = ResteACharge::calculer($this->chiffrage(), 2, CategorieRevenu::NonPrecisee, $this->bareme());

        self::assertTrue($reste->estUneFourchette());
        self::assertSame(3000, $reste->aideMinimum()->euros());
        self::assertSame(24000, $reste->aideMaximum()->euros());
    }

    public function testLeResteMinimumCorrespondALAideMaximum(): void
    {
        $reste = ResteACharge::calculer($this->chiffrage(), 2, CategorieRevenu::NonPrecisee, $this->bareme());

        // 31 760 − 24 000 = 7 760 au mieux ; 31 760 − 3 000 = 28 760 au pire.
        self::assertSame(7760, $reste->minimum()->euros());
        self::assertSame(28760, $reste->maximum()->euros());
    }

    public function testSansGainSuffisantLAideEstNulleEtLeResteEgaleLeCout(): void
    {
        $reste = ResteACharge::calculer($this->chiffrage(), 1, CategorieRevenu::Modeste, $this->bareme());

        self::assertFalse($reste->ouvreDroit());
        self::assertSame(0, $reste->aideMaximum()->euros());
        self::assertSame(31760, $reste->minimum()->euros());
        self::assertSame(31760, $reste->maximum()->euros());
    }

    public function testLesRetouchesDuProEntrentDansLeCout(): void
    {
        // Menuiseries portées de 5 760 € à 8 400 € : le coût passe à 34 400 €,
        // l'aide reste écrêtée au plafond, donc le reste à charge augmente d'autant.
        $chiffrage = $this->chiffrage()->avecRetouches([Poste::Menuiseries->name => Montant::fromEuros(8400)]);

        $reste = ResteACharge::calculer($chiffrage, 2, CategorieRevenu::Modeste, $this->bareme());

        self::assertSame(34400, $reste->coutHt()->euros());
        self::assertSame(18000, $reste->aideMaximum()->euros());
        self::assertSame(16400, $reste->minimum()->euros());
    }

    public function testUneAidePlusGrandeQueLeCoutNeRendPasLeResteNegatif(): void
    {
        // Petit chantier très aidé : l'aide est écrêtée au coût réel, on ne rend
        // jamais d'argent au ménage.
        $chiffrage = $this->chiffrage()->avecRetouches([
            Poste::Murs->name => Montant::fromEuros(1000),
            Poste::Menuiseries->name => Montant::fromEuros(0),
            Poste::Chauffage->name => Montant::fromEuros(0),
        ]);

        $reste = ResteACharge::calculer($chiffrage, 3, CategorieRevenu::TresModeste, $this->bareme());

        self::assertSame(800, $reste->aideMaximum()->euros());
        self::assertSame(200, $reste->minimum()->euros());
    }

    private function chiffrage(): Chiffrage
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, Toulouse', 100, 0, Dpe::E, Dpe::C, 5);

        return Chiffrage::derive(
            Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries, Poste::Chauffage),
            GrilleCout::parDefautNationale(PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'))),
            QuantitesTravaux::depuis($saisie),
        );
    }

    private function bareme(): BaremeAides
    {
        return BaremeAides::parDefautNational(PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01')));
    }
}
