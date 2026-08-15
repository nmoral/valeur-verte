<?php

declare(strict_types=1);

namespace App\BackOffice\Aide;

use App\BackOffice\GrilleCout\Montant;

/**
 * Calcul de l'aide MaPrimeRénov' rénovation d'ampleur (#26) :
 *
 *     aide = min(coût HT, plafond(gain de classes)) × taux(catégorie)
 *
 * Montant **estimatif**, dérivé d'une catégorie de revenus déclarée par le pro : il
 * ne préjuge ni de l'éligibilité du ménage ni de l'instruction du dossier par l'Anah.
 */
final readonly class AideRenovationAmpleur
{
    public static function pour(
        Montant $coutHt,
        int $gainDeClasses,
        CategorieRevenu $categorie,
        BaremeAides $bareme,
    ): Montant {
        if (!$bareme->ouvreDroit($gainDeClasses)) {
            return Montant::fromEuros(0);
        }

        $assiette = min($coutHt->euros(), $bareme->plafondDepenses($gainDeClasses)->euros());

        return Montant::fromEuros(intdiv($assiette * $bareme->tauxPourCent($categorie), 100));
    }

    /**
     * Bornes de l'aide sur l'ensemble des tranches, quand la catégorie n'est pas précisée.
     */
    public static function fourchette(Montant $coutHt, int $gainDeClasses, BaremeAides $bareme): FourchetteAide
    {
        $montants = [];
        foreach (CategorieRevenu::tranches() as $categorie) {
            $montants[] = self::pour($coutHt, $gainDeClasses, $categorie, $bareme)->euros();
        }

        return FourchetteAide::de(
            Montant::fromEuros(min($montants)),
            Montant::fromEuros(max($montants)),
        );
    }
}
