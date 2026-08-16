<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Paramètres d'essai d'une formule (#61) : durée en jours, quota de rapports et
 * plafond de comptes (nul = illimité). Value object validé à la frontière —
 * des valeurs non positives n'ont pas de sens.
 */
final readonly class ParametresFormule
{
    public function __construct(
        private int $dureeEssaiJours,
        private int $quotaRapports,
        private ?int $plafondComptes,
    ) {
        if ($dureeEssaiJours <= 0) {
            throw new \InvalidArgumentException("La durée d'essai doit être strictement positive.");
        }
        if ($quotaRapports <= 0) {
            throw new \InvalidArgumentException('Le quota de rapports doit être strictement positif.');
        }
        if (null !== $plafondComptes && $plafondComptes <= 0) {
            throw new \InvalidArgumentException('Le plafond de comptes, s\'il est posé, doit être strictement positif.');
        }
    }

    public function dureeEssaiJours(): int
    {
        return $this->dureeEssaiJours;
    }

    public function quotaRapports(): int
    {
        return $this->quotaRapports;
    }

    public function plafondComptes(): ?int
    {
        return $this->plafondComptes;
    }
}
