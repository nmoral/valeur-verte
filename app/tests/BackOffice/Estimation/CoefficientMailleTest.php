<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Estimation;

use App\BackOffice\Estimation\ClasseNonPubliee;
use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
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

    /**
     * #260 : quand la maille porte `ic_dpe` (fourchette SE-based du moteur), la
     * fourchette € découle de l'**incertitude des coefficients**, pas de l'erreur
     * médiane. Δ = coef(C)−coef(E) ; la demi-largeur de l'écart combine les deux
     * demi-IC (indépendance : √(demi_C² + demi_E²)) puis passe en € par exp.
     */
    public function testLaFourchetteDecouleDeLIcDpeQuandDisponible(): void
    {
        $maille = self::maille(
            ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
            erreurMediane: 20.0,
            icDpe: ['D' => [0.0, 0.0], 'C' => [-0.05, 0.25], 'E' => [-0.30, -0.10]],
        );

        $restitution = $maille->estimer(Dpe::E, Dpe::C, prixBaseEuros: 100000, prixSaisiParLePro: false);

        $demiC = (0.25 - (-0.05)) / 2.0;   // 0,15
        $demiE = (-0.10 - (-0.30)) / 2.0;  // 0,10
        $demiEcart = sqrt($demiC ** 2 + $demiE ** 2);
        $delta = 0.10 - (-0.20);
        self::assertSame((int) round(100000 * (exp($delta) - 1.0)), $restitution->montantEuros());
        self::assertSame(
            (int) round(100000 * (exp($delta - $demiEcart) - 1.0)),
            $restitution->borneBasseEuros(),
        );
        self::assertSame(
            (int) round(100000 * (exp($delta + $demiEcart) - 1.0)),
            $restitution->borneHauteEuros(),
        );
        // La fourchette SE-based diffère de l'ancienne (erreur médiane → 27989/41983).
        self::assertNotSame(27989, $restitution->borneBasseEuros());
    }

    /**
     * #260 : avec `ic_dpe`, un même DPE avant/après garde un gain ET une fourchette
     * nuls — l'écart des coefficients est exactement zéro (classes identiques,
     * corrélation 1), pas √2·demi_IC. Sinon on afficherait une fourchette autour de 0.
     */
    public function testUnMemeDpeAvecIcDonneUneFourchetteNulle(): void
    {
        $maille = self::maille(
            ['D' => 0.0, 'C' => 0.10],
            erreurMediane: 20.0,
            icDpe: ['D' => [0.0, 0.0], 'C' => [-0.05, 0.25]],
        );

        $restitution = $maille->estimer(Dpe::C, Dpe::C, 100000, false);

        self::assertSame(0, $restitution->montantEuros());
        self::assertSame(0, $restitution->borneBasseEuros());
        self::assertSame(0, $restitution->borneHauteEuros());
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
        $maille = self::maille(
            ['D' => 0.0, 'C' => 0.10],
            erreurMediane: 20.0,
            rayonKm: null,
            portee: PorteeEstimation::Departemental,
        );

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
     * Rendu par bande (#169) : aux crans EPCI/DÉPT, une classe sous-effectif est
     * fusionnée dans sa bande. La maison classée G n'a pas de coefficient propre,
     * mais `bandes` la rattache à F : on chiffre via le représentant, au lieu de
     * refuser l'estimation.
     */
    public function testUneClasseFusionneeEstServieViaSaBande(): void
    {
        $maille = self::maille(
            ['D' => 0.0, 'C' => 0.10, 'F' => -0.30],
            erreurMediane: 25.0,
            portee: PorteeEstimation::Epci,
            bandes: ['F' => 'F', 'G' => 'F'],
        );

        $viaBande = $maille->estimer(Dpe::G, Dpe::C, 100000, false);
        $direct = $maille->estimer(Dpe::F, Dpe::C, 100000, false);

        self::assertSame($direct->montantEuros(), $viaBande->montantEuros());
    }

    /**
     * La substitution de bande est signalée à l'usager : « classe G estimée via
     * la bande F ». Seules les classes réellement fusionnées (représentant ≠
     * classe) sont mentionnées ; une bande identité (F→F) ne l'est pas.
     */
    public function testLaBandeAppliqueeEstSignaleeDansLaRestitution(): void
    {
        $maille = self::maille(
            ['D' => 0.0, 'C' => 0.10, 'F' => -0.30],
            erreurMediane: 25.0,
            portee: PorteeEstimation::Epci,
            bandes: ['F' => 'F', 'G' => 'F'],
        );

        $restitution = $maille->estimer(Dpe::G, Dpe::C, 100000, false);

        self::assertSame(['G' => 'F'], $restitution->bandesAppliquees());
    }

    public function testSansBandeAppliqueeLaRestitutionNeSignaleRien(): void
    {
        $maille = self::maille(['D' => 0.0, 'C' => 0.10, 'E' => -0.20], erreurMediane: 20.0);

        $restitution = $maille->estimer(Dpe::E, Dpe::C, 100000, false);

        self::assertSame([], $restitution->bandesAppliquees());
    }

    public function testLaMailleTransmetSaPorteeEtSonPalierALaRestitution(): void
    {
        $maille = self::maille(
            ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
            erreurMediane: 25.0,
            rayonKm: null,
            portee: PorteeEstimation::Epci,
            palier: PalierFiabilite::Degrade,
        );

        $restitution = $maille->estimer(Dpe::E, Dpe::C, 100000, false);

        self::assertTrue($restitution->estDegradee());
        self::assertSame('intercommunal (EPCI)', $restitution->intitulePortee());
    }

    /**
     * #261 : une maille fortement régularisée par le pooling (poids w < 0,5, le
     * département a pesé > 50 %) est signalée « partiellement rapprochée du
     * département » ; sinon (peu régularisée, ou pas de pooling) elle ne l'est pas.
     */
    public function testUneMailleFortementRegulariseeEstPartiellementRapprochee(): void
    {
        $maille = self::maille(['D' => 0.0, 'C' => 0.10, 'E' => -0.20], erreurMediane: 20.0, poidsPooling: 0.3);

        $restitution = $maille->estimer(Dpe::E, Dpe::C, 100000, false);

        self::assertTrue($restitution->estPartiellementRapprochee());
    }

    public function testUneMaillePeuRegulariseeNEstPasPartiellementRapprochee(): void
    {
        $maille = self::maille(['D' => 0.0, 'C' => 0.10, 'E' => -0.20], erreurMediane: 20.0, poidsPooling: 0.8);

        $restitution = $maille->estimer(Dpe::E, Dpe::C, 100000, false);

        self::assertFalse($restitution->estPartiellementRapprochee());
    }

    public function testUneMailleSansPoolingNEstPasPartiellementRapprochee(): void
    {
        $maille = self::maille(['D' => 0.0, 'C' => 0.10, 'E' => -0.20], erreurMediane: 20.0);

        $restitution = $maille->estimer(Dpe::E, Dpe::C, 100000, false);

        self::assertFalse($restitution->estPartiellementRapprochee());
    }

    /**
     * @param array<string, float>               $coefDpe
     * @param array<string, string>              $bandes
     * @param array<string, array{float, float}> $icDpe
     */
    private static function maille(
        array $coefDpe,
        float $erreurMediane,
        int $n = 100,
        ?int $rayonKm = 10,
        int $millesime = 2024,
        PorteeEstimation $portee = PorteeEstimation::Local,
        PalierFiabilite $palier = PalierFiabilite::Standard,
        array $bandes = [],
        array $icDpe = [],
        ?float $poidsPooling = null,
    ): CoefficientMaille {
        return CoefficientMaille::depuis(
            millesime: $millesime,
            rayonKm: $rayonKm,
            n: $n,
            erreurMediane: $erreurMediane,
            coefDpe: $coefDpe,
            portee: $portee,
            palier: $palier,
            bandes: $bandes,
            icDpe: $icDpe,
            poidsPooling: $poidsPooling,
        );
    }
}
