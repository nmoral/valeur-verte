<?php

declare(strict_types=1);

namespace App\Tests\PourquoiIci;

use App\PourquoiIci\LevierDepartemental;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Levier de rénovation dérivé au niveau département (#341, Lot 4).
 *
 * Le marqueur mesuré `levier_renovation` n'est produit qu'au niveau communal
 * (Lot 2). Pour que la garde de cohérence des hypothèses tienne aussi sur la page
 * département, on classe le gain E→C **médian** du département en tier. Seuil
 * app-side (pas les percentiles nationaux du Lot 2), assumé : `fort` ≥ 30 %,
 * `faible` ≤ 15 %, `moyen` entre — cohérent avec les exemples de l'issue
 * (Étain +35 fort, Talence +22 moyen, Var +8 faible).
 */
final class LevierDepartementalTest extends TestCase
{
    #[DataProvider('cas')]
    public function testClasseLeGainMedianEnTier(int $gainEc, string $attendu): void
    {
        self::assertSame($attendu, LevierDepartemental::depuis($gainEc));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function cas(): iterable
    {
        yield 'gain élevé → fort' => [35, 'fort'];
        yield 'borne haute incluse → fort' => [30, 'fort'];
        yield 'gain intermédiaire → moyen' => [22, 'moyen'];
        yield 'juste au-dessus du plancher → moyen' => [16, 'moyen'];
        yield 'gain faible → faible' => [8, 'faible'];
        yield 'borne basse incluse → faible' => [15, 'faible'];
    }
}
