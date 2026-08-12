<?php

declare(strict_types=1);

namespace App\BackOffice\GrilleCout;

enum Unite
{
    case EuroParM2;
    case EuroParUnite;
    case EuroForfait;

    public function symbole(): string
    {
        return match ($this) {
            self::EuroParM2 => '€/m²',
            self::EuroParUnite => '€/u',
            self::EuroForfait => '€',
        };
    }
}
