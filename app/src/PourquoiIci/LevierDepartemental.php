<?php

declare(strict_types=1);

namespace App\PourquoiIci;

/**
 * Levier de rénovation dérivé au niveau département (#341, Lot 4).
 *
 * Le marqueur mesuré `levier_renovation` n'est produit qu'au niveau communal
 * (Lot 2, portée communale). Pour que la garde de cohérence des hypothèses tienne
 * aussi sur la page département, on classe le gain E→C **médian** du département
 * dans le même vocabulaire (`fort`/`moyen`/`faible`) et on l'injecte comme le
 * marqueur `levier_renovation` du département.
 *
 * Seuil **app-side** assumé (et non les percentiles nationaux du Lot 2, non
 * persistés) : aligné sur `Constats::SEUIL_ELEVE` (30 %) et sur les exemples de
 * l'issue — Étain +35 % (fort), Talence +22 % (moyen), Var +8 % (faible).
 */
final readonly class LevierDepartemental
{
    private const int SEUIL_FORT = 30;
    private const int SEUIL_FAIBLE = 15;

    public static function depuis(int $gainEcPct): string
    {
        if ($gainEcPct >= self::SEUIL_FORT) {
            return 'fort';
        }

        if ($gainEcPct <= self::SEUIL_FAIBLE) {
            return 'faible';
        }

        return 'moyen';
    }
}
