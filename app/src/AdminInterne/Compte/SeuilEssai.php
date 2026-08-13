<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

/**
 * Seuil d'essai (#47/#65) : nombre de rapports offerts avant bascule payant.
 * Réglage éditable dans l'admin ; par défaut 10 (à calibrer, #65). La persistance
 * est ajoutée à l'étape repository — ici le cœur métier et sa validation.
 */
final class SeuilEssai
{
    private const DEFAUT = 10;

    private function __construct(private readonly int $valeur)
    {
        if ($this->valeur <= 0) {
            throw new \InvalidArgumentException("Le seuil d'essai doit être strictement positif.");
        }
    }

    public static function parDefaut(): self
    {
        return new self(self::DEFAUT);
    }

    public static function de(int $valeur): self
    {
        return new self($valeur);
    }

    public function valeur(): int
    {
        return $this->valeur;
    }
}
