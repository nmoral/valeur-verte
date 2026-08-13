<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

/**
 * Port de lecture des coefficients pré-calculés (§10.6). Renvoie la maille du
 * millésime le plus récent pour un code INSEE, ou null si la commune n'est pas
 * couverte. L'implémentation lit ``data.coefficient_maille`` en lecture seule.
 */
interface FournisseurCoefficientMaille
{
    public function pour(string $codeInsee): ?CoefficientMaille;
}
