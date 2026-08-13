<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

/**
 * Indicateur synthétique de fiabilité d'une estimation (§9.7). Sans commercial
 * pour l'expliquer, il porte seul la lecture de la qualité (#110). La cascade
 * est déterministe : Fragile prime, puis Solide, sinon Correct. Le repli
 * départemental (rayon absent) est toujours Fragile, quel que soit l'effectif.
 */
enum IndicateurFiabilite: string
{
    case Solide = 'solide';
    case Correct = 'correct';
    case Fragile = 'fragile';

    private const EFFECTIF_FRAGILE = 50;
    private const EFFECTIF_SOLIDE = 80;
    private const RAYON_SOLIDE_MAX = 20;

    public static function depuis(int $n, ?int $rayonKm): self
    {
        if ($n < self::EFFECTIF_FRAGILE || null === $rayonKm) {
            return self::Fragile;
        }

        if ($n >= self::EFFECTIF_SOLIDE && $rayonKm <= self::RAYON_SOLIDE_MAX) {
            return self::Solide;
        }

        return self::Correct;
    }
}
