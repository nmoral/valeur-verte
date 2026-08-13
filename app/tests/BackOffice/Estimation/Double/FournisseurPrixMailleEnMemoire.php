<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Estimation\Double;

use App\BackOffice\Estimation\FournisseurPrixMaille;

/**
 * Double en mémoire : renvoie la médiane du prix au m² configurée par test.
 */
final class FournisseurPrixMailleEnMemoire implements FournisseurPrixMaille
{
    /** @var array<string, float> */
    private array $parCode = [];

    public function definir(string $codeInsee, float $prixM2Median): void
    {
        $this->parCode[$codeInsee] = $prixM2Median;
    }

    public function prixM2Median(string $codeInsee): ?float
    {
        return $this->parCode[$codeInsee] ?? null;
    }
}
