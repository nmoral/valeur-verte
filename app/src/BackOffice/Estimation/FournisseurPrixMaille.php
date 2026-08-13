<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

/**
 * Port de lecture du prix de marché d'une commune : médiane du prix au m² des
 * ventes de la maille (§6). Sert de prix de base par défaut, ajustable par le
 * pro. Renvoie null quand aucune vente ne permet de l'établir.
 */
interface FournisseurPrixMaille
{
    public function prixM2Median(string $codeInsee): ?float;
}
