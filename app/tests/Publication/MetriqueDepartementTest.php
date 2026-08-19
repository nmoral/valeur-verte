<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\Maille;
use App\Publication\MetriquesDepartementales;
use PHPUnit\Framework\TestCase;

/**
 * Agrégation des mailles publiées par département — ce que la carte colore (#224).
 *
 * Un département n'est pas un marché : la valeur affichée est la **médiane** de ses
 * mailles, jamais une moyenne (une commune atypique ne doit pas tirer le chiffre).
 */
final class MetriqueDepartementTest extends TestCase
{
    public function testLesMaillesSontRegroupeesParDepartement(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            self::maille('31001'),
            self::maille('31002'),
            self::maille('09001'),
        ]);

        self::assertSame(['09', '31'], $metriques->codes());
        self::assertSame(2, $metriques->pour('31')?->zones);
        self::assertSame(1, $metriques->pour('09')?->zones);
    }

    public function testLeGainDuDepartementEstLaMedianeDeSesMailles(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            self::maille('31001', gainLog: 0.10),
            self::maille('31002', gainLog: 0.20),
            self::maille('31003', gainLog: 0.60),
        ]);

        // médiane des gains {11 %, 22 %, 82 %} = 22 %
        self::assertSame(22, $metriques->pour('31')?->gainEc);
    }

    public function testLaMedianeDUnEffectifPairEstLaMoyenneDesDeuxValeursCentrales(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            self::maille('31001', gainLog: 0.10),
            self::maille('31002', gainLog: 0.20),
            self::maille('31003', gainLog: 0.30),
            self::maille('31004', gainLog: 0.40),
        ]);

        // gains {11, 22, 35, 49} → (22 + 35) / 2 = 28,5 → 29
        self::assertSame(29, $metriques->pour('31')?->gainEc);
    }

    public function testLesMaillesSansClasseCPubliableSontIgnoreesDuGain(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            self::maille('31001', gainLog: 0.20),
            new Maille('31002', 'local', ['D' => 0.0, 'E' => -0.19]),
        ]);

        self::assertSame(22, $metriques->pour('31')?->gainEc);
        self::assertSame(2, $metriques->pour('31')->zones, 'La maille compte comme zone mesurée, même sans gain E→C.');
    }

    public function testUnDepartementSansAucunGainCalculableNEnAffichePas(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            new Maille('31001', 'local', ['D' => 0.0]),
        ]);

        $metrique = $metriques->pour('31');

        self::assertNotNull($metrique);
        self::assertNull($metrique->gainEc);
        self::assertNull($metrique->gainCb);
    }

    public function testLeGainVersBEstLaMedianeDuCranDepuisC(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            new Maille('31001', 'local', ['D' => 0.0, 'C' => 0.11, 'B' => 0.19, 'E' => -0.19]),
            new Maille('31002', 'local', ['D' => 0.0, 'C' => 0.11, 'B' => 0.27, 'E' => -0.19]),
        ]);

        // cran C→B : exp(0,08)−1 = 8 % ; exp(0,16)−1 = 17 % → médiane {8, 17} = 13 %.
        self::assertSame(13, $metriques->pour('31')?->gainCb);
    }

    public function testUneMailleSansClasseBEstIgnoreeDuCranVersB(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            new Maille('31001', 'local', ['D' => 0.0, 'C' => 0.11, 'B' => 0.19, 'E' => -0.19]),
            new Maille('31002', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
        ]);

        // Seule 31001 porte un B publiable : le cran C→B est le sien (8 %).
        self::assertSame(8, $metriques->pour('31')?->gainCb);
    }

    public function testUnDepartementSansAucunBNAffichePasDeCranVersB(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            self::maille('31001'),
        ]);

        self::assertNull($metriques->pour('31')?->gainCb);
    }

    public function testLaPartDepartementaleMesureLesMaillesEstimeesALEchelleDuDepartement(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([
            self::maille('31001', mode: 'local'),
            self::maille('31002', mode: 'local'),
            self::maille('31003', mode: 'epci'),
            self::maille('31004', mode: 'departemental'),
        ]);

        self::assertSame(25, $metriques->pour('31')?->partDepartementale);
    }

    public function testLeNomDuDepartementEstResolu(): void
    {
        $metriques = MetriquesDepartementales::depuisMailles([self::maille('31001')]);

        self::assertSame('Haute-Garonne', $metriques->pour('31')?->nom);
    }

    private static function maille(string $codeInsee, string $mode = 'local', float $gainLog = 0.30): Maille
    {
        return new Maille($codeInsee, $mode, ['D' => 0.0, 'C' => $gainLog / 2, 'E' => -$gainLog / 2]);
    }
}
