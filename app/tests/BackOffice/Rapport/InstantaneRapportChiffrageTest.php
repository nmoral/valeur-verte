<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\Chiffrage\QuantitesTravaux;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Restitution\Restitution;
use PHPUnit\Framework\TestCase;

/**
 * Chiffrage dérivé et barème d'aides figés dans l'instantané (#26, §9.1).
 *
 * Sans eux, le reste à charge ne serait pas reproductible : le chiffrage dépend de
 * la grille du pro (#34), qu'il peut modifier, et l'aide du barème national, qui
 * change à chaque réforme. Un PDF réimprimé dans deux ans doit afficher le coût et
 * l'aide **du jour de l'audit**, pas ceux du jour de la réimpression.
 */
final class InstantaneRapportChiffrageTest extends TestCase
{
    public function testLeChiffrageDeriveEstFigeEtRelu(): void
    {
        $instantane = $this->instantane($this->chiffrage(), $this->bareme());

        $relu = InstantaneRapport::fromArray($instantane->toArray());

        $chiffrage = $relu->chiffrage();
        self::assertNotNull($chiffrage);
        self::assertSame(12000, $chiffrage->montant(Poste::Murs)->euros());
        self::assertSame(5760, $chiffrage->montant(Poste::Menuiseries)->euros());
        self::assertSame(17760, $chiffrage->total()->euros());
    }

    public function testLeBaremeDAidesEstFigeEtRelu(): void
    {
        $instantane = $this->instantane($this->chiffrage(), $this->bareme());

        $relu = InstantaneRapport::fromArray($instantane->toArray());

        $bareme = $relu->baremeAides();
        self::assertNotNull($bareme);
        self::assertSame(80, $bareme->tauxPourCent(CategorieRevenu::TresModeste));
        self::assertSame(30000, $bareme->plafondDepenses(2)->euros());
        self::assertSame(55, $bareme->tauxTvaPourMille());
    }

    public function testUnInstantaneAncienSansChiffrageResteRelisible(): void
    {
        // Les rapports émis avant #26 n'ont ni chiffrage ni barème : ils doivent
        // continuer à se relire, sans reste à charge plutôt qu'en erreur.
        $ancien = $this->instantane(null, null)->toArray();
        unset($ancien['chiffrage'], $ancien['bareme_aides']);

        $relu = InstantaneRapport::fromArray($ancien);

        self::assertNull($relu->chiffrage());
        self::assertNull($relu->baremeAides());
        self::assertSame('12 rue des Lilas, Toulouse', $relu->saisie()->adresse());
    }

    private function chiffrage(): Chiffrage
    {
        return Chiffrage::derive(
            Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries),
            GrilleCout::parDefautNationale($this->periode()),
            QuantitesTravaux::depuis($this->saisie()),
        );
    }

    private function bareme(): BaremeAides
    {
        return BaremeAides::parDefautNational($this->periode());
    }

    private function instantane(?Chiffrage $chiffrage, ?BaremeAides $bareme): InstantaneRapport
    {
        return InstantaneRapport::creer(
            $this->saisie(),
            Restitution::depuis(69972, 55000, 85000, 134, 10, 2024, 147150, false),
            ContexteCalcul::depuis(-0.20, 0.10, 17.22),
            Branding::parDefaut(),
            ReferenceRapport::depuis('VV-20260815-CH1234'),
            new \DateTimeImmutable('2026-08-15 10:00:00'),
            Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries),
            $chiffrage,
            $bareme,
        );
    }

    private function saisie(): SaisieAudit
    {
        return SaisieAudit::depuis('12 rue des Lilas, Toulouse', 100, 0, Dpe::E, Dpe::C, 5);
    }

    private function periode(): PeriodeValidite
    {
        return PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'));
    }
}
