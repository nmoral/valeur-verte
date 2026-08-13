<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use App\BackOffice\Restitution\Restitution;
use PHPUnit\Framework\TestCase;

/**
 * Instantané de rapport immuable (#33, §9.1, D5) : la sérialisation JSONB de
 * l'intégralité du contexte permet de régénérer le PDF à l'identique — jamais
 * recalculé. On vérifie le round-trip et la reconstruction fidèle des objets
 * du domaine (saisie, restitution, marque, référence).
 */
final class InstantaneRapportTest extends TestCase
{
    public function testReconstruitLaSaisieLaRestitutionLaMarqueEtLaReference(): void
    {
        $instantane = InstantaneRapport::fromArray($this->instantane()->toArray());

        self::assertEquals($this->saisie(), $instantane->saisie());
        self::assertEquals($this->restitution(), $instantane->restitution());
        self::assertEquals($this->contexte(), $instantane->contexte());
        self::assertEquals($this->branding(), $instantane->branding());
        self::assertEquals(ReferenceRapport::depuis('VV-20260813-A1B2C3'), $instantane->reference());
    }

    public function testUneCommuneHorsCouvertureFigeUnInstantaneSansChiffre(): void
    {
        $sansChiffre = InstantaneRapport::creer(
            $this->saisie(),
            null,
            null,
            $this->branding(),
            ReferenceRapport::depuis('VV-20260813-A1B2C3'),
            new \DateTimeImmutable('2026-08-13 10:00:00'),
        );

        $relu = InstantaneRapport::fromArray($sansChiffre->toArray());

        self::assertNull($relu->restitution());
        self::assertNull($relu->contexte());
        self::assertEquals($this->saisie(), $relu->saisie());
    }

    public function testLeContenuJsonbPorteLaReferenceEtLeMillesime(): void
    {
        $donnees = $this->instantane()->toArray();

        self::assertSame('VV-20260813-A1B2C3', $donnees['reference']);
        self::assertIsArray($donnees['restitution']);
        self::assertSame(2024, $donnees['restitution']['millesime']);
    }

    /**
     * Reproductibilité de la cascade (#169) : le cran (portée), le tier dégradé
     * (palier) et les bandes DPE appliquées sont figés dans l'instantané, sinon
     * un rapport EPCI/départemental régénéré perdrait sa formulation et son
     * bandeau — le PDF ne serait plus à l'identique.
     */
    public function testLInstantaneFigeLaPorteeLePalierEtLesBandesDeLaCascade(): void
    {
        $restitution = Restitution::depuis(
            montantEuros: 12000,
            borneBasseEuros: 6000,
            borneHauteEuros: 18000,
            n: 60,
            rayonKm: null,
            millesime: 2024,
            prixBaseEuros: 100000,
            prixSaisiParLePro: false,
            portee: PorteeEstimation::Epci,
            palier: PalierFiabilite::Degrade,
            bandesAppliquees: ['G' => 'F'],
        );
        $instantane = InstantaneRapport::creer(
            $this->saisie(),
            $restitution,
            $this->contexte(),
            $this->branding(),
            ReferenceRapport::depuis('VV-20260813-A1B2C3'),
            new \DateTimeImmutable('2026-08-13 10:00:00'),
        );

        $relu = InstantaneRapport::fromArray($instantane->toArray())->restitution();

        self::assertNotNull($relu);
        self::assertEquals($restitution, $relu);
        self::assertSame('intercommunal (EPCI)', $relu->intitulePortee());
        self::assertTrue($relu->estDegradee());
        self::assertSame(['G' => 'F'], $relu->bandesAppliquees());
    }

    private function instantane(): InstantaneRapport
    {
        return InstantaneRapport::creer(
            $this->saisie(),
            $this->restitution(),
            $this->contexte(),
            $this->branding(),
            ReferenceRapport::depuis('VV-20260813-A1B2C3'),
            new \DateTimeImmutable('2026-08-13 10:00:00'),
        );
    }

    private function saisie(): SaisieAudit
    {
        return SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C, 4);
    }

    private function restitution(): Restitution
    {
        return Restitution::depuis(69972, 55000, 85000, 134, 10, 2024, 147150, false);
    }

    private function contexte(): ContexteCalcul
    {
        return ContexteCalcul::depuis(-0.20, 0.10, 17.22);
    }

    private function branding(): Branding
    {
        return Branding::creer(
            Couleur::depuisHex('#B8480F'),
            '1234-RGE-31',
            '05 61 00 00 00',
            'SARL Rénov Toulouse',
            'https://cdn.example.com/logo.png',
        );
    }
}
