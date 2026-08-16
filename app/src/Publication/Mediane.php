<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Médiane d'une série de valeurs entières.
 *
 * Un département n'est pas un marché : ce qu'on affiche est toujours la valeur
 * centrale de ses mailles, jamais une moyenne — une commune atypique ne doit pas
 * tirer le chiffre du département.
 */
final readonly class Mediane
{
    /**
     * @param list<int> $valeurs
     */
    public static function de(array $valeurs): ?int
    {
        if ([] === $valeurs) {
            return null;
        }

        sort($valeurs);
        $milieu = intdiv(\count($valeurs), 2);

        if (0 !== \count($valeurs) % 2) {
            return $valeurs[$milieu];
        }

        return (int) round(($valeurs[$milieu - 1] + $valeurs[$milieu]) / 2);
    }
}
