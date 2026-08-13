<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Estimation\Double;

use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Estimation\FournisseurCoefficientMaille;

/**
 * Double en mémoire : renvoie la maille configurée par test, sans base « data ».
 */
final class FournisseurCoefficientMailleEnMemoire implements FournisseurCoefficientMaille
{
    /** @var array<string, CoefficientMaille> */
    private array $parCode = [];

    public function definir(string $codeInsee, CoefficientMaille $maille): void
    {
        $this->parCode[$codeInsee] = $maille;
    }

    public function pour(string $codeInsee): ?CoefficientMaille
    {
        return $this->parCode[$codeInsee] ?? null;
    }
}
