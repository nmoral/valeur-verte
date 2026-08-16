<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

use App\BackOffice\Rapport\Dpe;

/**
 * Un palier de l'effet de seuil (#27) : le gain en euros pour aller de la classe
 * actuelle du bien à une classe DPE meilleure. `estSeuil` marque la classe C, où
 * le marché rémunère le franchissement ; `estCible` marque la classe visée par le pro.
 */
final readonly class Palier
{
    public function __construct(
        private Dpe $classeCible,
        private int $gainEuros,
        private bool $estSeuil,
        private bool $estCible,
    ) {
    }

    public function classeCible(): Dpe
    {
        return $this->classeCible;
    }

    public function gainEuros(): int
    {
        return $this->gainEuros;
    }

    public function estSeuil(): bool
    {
        return $this->estSeuil;
    }

    public function estCible(): bool
    {
        return $this->estCible;
    }
}
