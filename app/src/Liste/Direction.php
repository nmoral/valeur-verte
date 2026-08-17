<?php

declare(strict_types=1);

namespace App\Liste;

/**
 * Sens de tri d'une liste. Normalise l'entrée utilisateur : toute valeur non
 * reconnue retombe sur l'ordre ascendant (pas d'erreur, pas d'injection).
 */
enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public static function depuis(string $valeur): self
    {
        return self::tryFrom(strtolower(trim($valeur))) ?? self::Asc;
    }

    public function inverse(): self
    {
        return self::Asc === $this ? self::Desc : self::Asc;
    }

    public function versSql(): string
    {
        return self::Asc === $this ? 'ASC' : 'DESC';
    }
}
