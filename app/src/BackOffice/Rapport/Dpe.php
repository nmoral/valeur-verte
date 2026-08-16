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

    /**
     * Nombre de classes franchies pour aller de cette étiquette à `$apres`.
     * Positif quand le bien s'améliore, négatif s'il se dégrade — la dégradation
     * reste visible plutôt que confondue avec un gain nul.
     *
     * C'est l'entrée du barème d'aides (#26) : la rénovation d'ampleur se dimensionne
     * sur le gain de classes, pas sur la liste des gestes.
     */
    public function classesGagneesVers(self $apres): int
    {
        return $this->rang() - $apres->rang();
    }

    private function rang(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
