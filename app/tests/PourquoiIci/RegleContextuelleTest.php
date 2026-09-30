<?php

declare(strict_types=1);

namespace App\Tests\PourquoiIci;

use App\PourquoiIci\Marqueurs;
use App\PourquoiIci\RegleContextuelle;
use PHPUnit\Framework\TestCase;

/**
 * Une règle de la liste blanche (#341, Lot 3).
 *
 * Une règle porte des conditions de CONTEXTE (marqueurs contextuels qui la
 * déclenchent) et une garde de COHÉRENCE (sur le marqueur mesuré, ex.
 * `levier_renovation`). Elle ne s'applique QUE si le contexte est réuni ET que la
 * mesure pointe dans le même sens. Une mesure qui contredit le contexte supprime
 * la règle — jamais de récit contredit par nos propres chiffres.
 */
final class RegleContextuelleTest extends TestCase
{
    public function testSAppliqueQuandContexteEtCoherenceSontReunis(): void
    {
        $regle = RegleContextuelle::depuis(
            contexte: ['climat' => ['froid']],
            coherence: ['levier_renovation' => ['moyen', 'fort']],
            texte: 'Département froid : isoler paie probablement.',
        );

        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'levier_renovation' => 'fort']);

        self::assertTrue($regle->sApplique($marqueurs));
    }

    public function testGardeDeCoherenceSupprimeLaRegleSiLaMesureContredit(): void
    {
        $regle = RegleContextuelle::depuis(
            contexte: ['climat' => ['froid']],
            coherence: ['levier_renovation' => ['moyen', 'fort']],
            texte: 'Département froid : isoler paie probablement.',
        );

        // Contexte réuni (froid) mais la mesure dit « levier faible » : contradiction.
        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'levier_renovation' => 'faible']);

        self::assertFalse($regle->sApplique($marqueurs));
    }

    public function testNeSAppliquePasSiLeContexteNEstPasReuni(): void
    {
        $regle = RegleContextuelle::depuis(
            contexte: ['climat' => ['froid']],
            coherence: ['levier_renovation' => ['moyen', 'fort']],
            texte: '…',
        );

        $marqueurs = Marqueurs::depuis(['climat' => 'doux', 'levier_renovation' => 'fort']);

        self::assertFalse($regle->sApplique($marqueurs));
    }

    public function testToutesLesConditionsDeContexteDoiventTenir(): void
    {
        $regle = RegleContextuelle::depuis(
            contexte: ['climat' => ['doux'], 'marche' => ['tendu']],
            coherence: ['levier_renovation' => ['faible']],
            texte: 'Climat doux et marché tendu : le DPE pèse moins.',
        );

        $reunies = Marqueurs::depuis(
            ['climat' => 'doux', 'marche' => 'tendu', 'levier_renovation' => 'faible'],
        );
        $partielles = Marqueurs::depuis(
            ['climat' => 'doux', 'marche' => 'detendu', 'levier_renovation' => 'faible'],
        );

        self::assertTrue($regle->sApplique($reunies));
        self::assertFalse($regle->sApplique($partielles));
    }

    public function testUneRegleSansGardeNeDependQueDuContexte(): void
    {
        $regle = RegleContextuelle::depuis(
            contexte: ['densite' => ['rural']],
            coherence: [],
            texte: 'Territoire rural.',
        );

        self::assertTrue($regle->sApplique(Marqueurs::depuis(['densite' => 'rural'])));
        self::assertFalse($regle->sApplique(Marqueurs::depuis(['densite' => 'urbain'])));
    }
}
