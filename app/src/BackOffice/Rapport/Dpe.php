<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

enum Dpe: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';
    case F = 'F';
    case G = 'G';

    /**
     * Vrai si cette étiquette est strictement plus favorable que l'autre
     * (A est la meilleure, G la pire). Sert la borne « DPE cible meilleur
     * que l'actuel » (§9.8).
     */
    public function estMeilleurQue(self $autre): bool
    {
        return $this->rang() < $autre->rang();
    }

    private function rang(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
