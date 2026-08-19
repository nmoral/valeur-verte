<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Énumération française d'une liste de noms : des virgules, puis « et » avant le
 * dernier. Sert à citer des départements dans les constats de la page publique
 * sans figer la liste dans le gabarit.
 */
final class Enumeration
{
    /**
     * @param list<string> $elements
     */
    public static function et(array $elements): string
    {
        if ([] === $elements) {
            return '';
        }

        $dernier = array_pop($elements);
        if ([] === $elements) {
            return $dernier;
        }

        return implode(', ', $elements).' et '.$dernier;
    }
}
