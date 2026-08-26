<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\BackOffice\Restitution\IndicateurFiabilite;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use App\Publication\FicheCommune;
use App\Publication\IndexabiliteCommune;
use App\Publication\Maille;
use PHPUnit\Framework\TestCase;

/**
 * Objet de présentation publique d'une commune (#360, SEO Vague 2 #338) : agrège
 * la maille publiée en chiffres prêts pour la page — décote/surcote par étiquette,
 * prix m² par classe, gains de cran, effectif, portée et indicateur de fiabilité.
 * Les effets DPE sont des effets log de base D ; le lisible est ``exp(Δ) − 1``.
 */
final class FicheCommuneTest extends TestCase
{
    public function testExposeLesGainsDeCran(): void
    {
        $fiche = $this->ficheStandard();

        self::assertSame(28, $fiche->gainEc(), 'E → C.');
        self::assertSame(8, $fiche->gainCb(), 'C → B.');
    }

    public function testExposeLaDecoteSurcoteParEtiquette(): void
    {
        // Base D (0 %) ; A surcote, E décote. Ordonné de A vers G, classes publiées.
        self::assertSame(
            ['A' => 35, 'B' => 20, 'C' => 11, 'D' => 0, 'E' => -14],
            $this->ficheStandard()->decoteSurcote(),
        );
    }

    public function testExposeLePrixM2MedianEtParClasse(): void
    {
        $fiche = $this->ficheStandard();

        self::assertSame(2500, $fiche->prixM2Median());
        self::assertSame(
            ['A' => 3375, 'B' => 2993, 'C' => 2763, 'D' => 2500, 'E' => 2152],
            $fiche->prixM2ParClasse(),
        );
    }

    public function testSansPrixDeMarcheLesPrixSontAbsentsSansErreur(): void
    {
        $fiche = $this->ficheStandard(prixM2: null);

        self::assertNull($fiche->prixM2Median());
        self::assertSame([], $fiche->prixM2ParClasse());
    }

    public function testExposeSesVariablesMesureesPourLeJsonLd(): void
    {
        // Données structurées Schema.org (#338) : chaque étiquette publiée porte,
        // dans l'ordre A→G, un PropertyValue de décote/surcote (%) suivi de son
        // prix au m² estimé (€/m²) quand un prix de marché est connu.
        self::assertSame(
            [
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette A', 'value' => 35, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Prix au m² étiquette A', 'value' => 3375, 'unitText' => '€/m²'],
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette B', 'value' => 20, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Prix au m² étiquette B', 'value' => 2993, 'unitText' => '€/m²'],
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette C', 'value' => 11, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Prix au m² étiquette C', 'value' => 2763, 'unitText' => '€/m²'],
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette D', 'value' => 0, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Prix au m² étiquette D', 'value' => 2500, 'unitText' => '€/m²'],
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette E', 'value' => -14, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Prix au m² étiquette E', 'value' => 2152, 'unitText' => '€/m²'],
            ],
            $this->ficheStandard()->variablesMesurees(),
        );
    }

    public function testSansPrixDeMarcheLesVariablesMesureesNePortentQueLaDecoteSurcote(): void
    {
        // Sans prix de marché, seule la décote/surcote reste mesurée : aucune
        // entrée prix, pas d'erreur.
        self::assertSame(
            [
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette A', 'value' => 35, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette B', 'value' => 20, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette C', 'value' => 11, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette D', 'value' => 0, 'unitText' => '%'],
                ['@type' => 'PropertyValue', 'name' => 'Décote/surcote étiquette E', 'value' => -14, 'unitText' => '%'],
            ],
            $this->ficheStandard(prixM2: null)->variablesMesurees(),
        );
    }

    public function testIndicateurEtPorteeDeriventDeLEffectifDuRayonEtDuMode(): void
    {
        $fiche = $this->ficheStandard();

        self::assertSame(IndicateurFiabilite::Solide, $fiche->indicateur(), 'n≥80 et rayon≤20 km.');
        self::assertSame(PorteeEstimation::Local, $fiche->portee());
        self::assertSame(90, $fiche->n);
        self::assertSame(15, $fiche->rayonKm);
        self::assertSame(2025, $fiche->millesime);
    }

    public function testUneMailleDepartementaleEstFragileEtDePorteeDepartementale(): void
    {
        // Repli départemental : rayon absent → Fragile quel que soit l'effectif ;
        // la décote/surcote reste calculable depuis les coefficients.
        $fiche = $this->ficheStandard(n: 400, rayonKm: null, mode: 'departemental');

        self::assertSame(IndicateurFiabilite::Fragile, $fiche->indicateur());
        self::assertSame(PorteeEstimation::Departemental, $fiche->portee());
        self::assertSame(11, $fiche->decoteSurcote()['C']);
    }

    /**
     * Indexabilité SEO (#361) : la portée locale non rapprochée est indexable, le
     * repli départemental et le pooling sous 0.5 basculent en noindex. La fiche
     * ne fait que porter la métrique et déléguer à {@see IndexabiliteCommune}.
     */
    public function testExposeSonIndexabilitePourLeGatingSeo(): void
    {
        self::assertSame(
            IndexabiliteCommune::Indexable,
            $this->ficheStandard()->indexabilite(),
            'Locale sans pooling → indexable.',
        );
        self::assertSame(
            IndexabiliteCommune::Repli,
            $this->ficheStandard(poidsPooling: 0.4)->indexabilite(),
            'Locale mais fortement rapprochée (pooling < 0.5) → repli.',
        );
        self::assertSame(
            IndexabiliteCommune::Repli,
            $this->ficheStandard(rayonKm: null, mode: 'departemental')->indexabilite(),
            'Repli départemental → repli.',
        );
    }

    private function ficheStandard(
        ?float $prixM2 = 2500.0,
        int $n = 90,
        ?int $rayonKm = 15,
        string $mode = 'local',
        ?float $poidsPooling = null,
    ): FicheCommune {
        $maille = new Maille('33063', $mode, ['D' => 0.0, 'C' => 0.10, 'B' => 0.18, 'E' => -0.15, 'A' => 0.30]);

        return FicheCommune::depuis(
            codeInsee: '33063',
            nom: 'Bordeaux',
            millesime: 2025,
            calculeLe: new \DateTimeImmutable('2026-02-08 04:12:00'),
            maille: $maille,
            n: $n,
            rayonKm: $rayonKm,
            erreurMediane: 17.0,
            prixM2Median: $prixM2,
            palier: PalierFiabilite::Standard,
            poidsPooling: $poidsPooling,
        );
    }
}
