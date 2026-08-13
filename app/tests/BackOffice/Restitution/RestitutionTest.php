<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Restitution\IndicateurFiabilite;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use App\BackOffice\Restitution\Restitution;
use PHPUnit\Framework\TestCase;

final class RestitutionTest extends TestCase
{
    public function testEllePorteLeContexteNonNegociable(): void
    {
        $restitution = self::restitution(
            montantEuros: 38000,
            borneBasseEuros: 29000,
            borneHauteEuros: 47000,
            n: 476,
            rayonKm: 25,
            millesime: 2024,
        );

        self::assertSame(38000, $restitution->montantEuros());
        self::assertSame(29000, $restitution->borneBasseEuros());
        self::assertSame(47000, $restitution->borneHauteEuros());
        self::assertSame(476, $restitution->n());
        self::assertSame(25, $restitution->rayonKm());
        self::assertSame(2024, $restitution->millesime());
    }

    public function testEllePorteLePrixDeBaseEtSonOrigine(): void
    {
        $mediane = self::restitution(prixBaseEuros: 147150, prixSaisiParLePro: false);
        $saisi = self::restitution(prixBaseEuros: 200000, prixSaisiParLePro: true);

        self::assertSame(147150, $mediane->prixBaseEuros());
        self::assertFalse($mediane->prixSaisiParLePro());
        self::assertSame(200000, $saisi->prixBaseEuros());
        self::assertTrue($saisi->prixSaisiParLePro());
    }

    public function testLaFiabiliteEstDeriveeDeLEffectifEtDuRayon(): void
    {
        $correct = self::restitution(n: 476, rayonKm: 25);
        $fragile = self::restitution(n: 300, rayonKm: null, portee: PorteeEstimation::Departemental);

        self::assertSame(IndicateurFiabilite::Correct, $correct->fiabilite());
        self::assertSame(IndicateurFiabilite::Fragile, $fragile->fiabilite());
    }

    public function testLaPhraseSuitLeFormatCible(): void
    {
        $restitution = self::restitution(
            montantEuros: 38000,
            borneBasseEuros: 29000,
            borneHauteEuros: 47000,
            n: 476,
            rayonKm: 25,
        );

        self::assertSame(
            '+38 000 € (fourchette 29 000 – 47 000 €, sur 476 transactions dans un rayon de 25 km)',
            $restitution->phrase(),
        );
    }

    public function testLaPhraseSignaleLeRepliDepartemental(): void
    {
        $restitution = self::restitution(
            montantEuros: 12000,
            borneBasseEuros: 6000,
            borneHauteEuros: 18000,
            n: 45,
            rayonKm: null,
            portee: PorteeEstimation::Departemental,
        );

        self::assertSame(
            '+12 000 € (fourchette 6 000 – 18 000 €, sur 45 transactions au niveau départemental)',
            $restitution->phrase(),
        );
    }

    /**
     * Cran EPCI (#169) : rayon absent comme le départemental, mais la phrase le
     * distingue — l'estimation vaut « au niveau intercommunal », pas départemental.
     */
    public function testLaPhraseNommeLIntercommunaliteAuCranEpci(): void
    {
        $restitution = self::restitution(
            montantEuros: 12000,
            borneBasseEuros: 6000,
            borneHauteEuros: 18000,
            n: 60,
            rayonKm: null,
            portee: PorteeEstimation::Epci,
        );

        self::assertSame(
            '+12 000 € (fourchette 6 000 – 18 000 €, sur 60 transactions au niveau intercommunal)',
            $restitution->phrase(),
        );
    }

    public function testLIntitulePorteeAlimenteLaStatistique(): void
    {
        self::assertSame('25 km', self::restitution(rayonKm: 25)->intitulePortee());
        self::assertSame(
            'intercommunal (EPCI)',
            self::restitution(rayonKm: null, portee: PorteeEstimation::Epci)->intitulePortee(),
        );
    }

    /**
     * Palier dégradé (#169) : erreur médiane entre 20 et 30 %, servi avec un
     * bandeau propre. Signal distinct de l'indicateur Fragile.
     */
    public function testLePalierDegradeEstPorteParLaRestitution(): void
    {
        self::assertTrue(self::restitution(palier: PalierFiabilite::Degrade)->estDegradee());
        self::assertFalse(self::restitution(palier: PalierFiabilite::Standard)->estDegradee());
    }

    /**
     * @param array<string, string> $bandesAppliquees
     */
    private static function restitution(
        int $montantEuros = 38000,
        int $borneBasseEuros = 29000,
        int $borneHauteEuros = 47000,
        int $n = 476,
        ?int $rayonKm = 25,
        int $millesime = 2024,
        int $prixBaseEuros = 147150,
        bool $prixSaisiParLePro = false,
        PorteeEstimation $portee = PorteeEstimation::Local,
        PalierFiabilite $palier = PalierFiabilite::Standard,
        array $bandesAppliquees = [],
    ): Restitution {
        return Restitution::depuis(
            montantEuros: $montantEuros,
            borneBasseEuros: $borneBasseEuros,
            borneHauteEuros: $borneHauteEuros,
            n: $n,
            rayonKm: $rayonKm,
            millesime: $millesime,
            prixBaseEuros: $prixBaseEuros,
            prixSaisiParLePro: $prixSaisiParLePro,
            portee: $portee,
            palier: $palier,
            bandesAppliquees: $bandesAppliquees,
        );
    }
}
