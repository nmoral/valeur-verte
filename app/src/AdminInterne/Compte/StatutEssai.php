<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

/**
 * Statut d'essai d'un compte pro (#47) : dérivé du nombre de rapports consommés
 * face au seuil d'essai. Objet-valeur ; le seuil est fourni par l'appelant (source
 * unique éditable dans l'admin), jamais codé en dur ici.
 */
final class StatutEssai
{
    private function __construct(
        private readonly int $consommes,
        private readonly int $seuil,
    ) {
    }

    public static function depuis(int $consommes, int $seuil): self
    {
        if ($seuil <= 0) {
            throw new \InvalidArgumentException("Le seuil d'essai doit être strictement positif.");
        }

        if ($consommes < 0) {
            throw new \InvalidArgumentException('Le nombre de rapports consommés ne peut pas être négatif.');
        }

        return new self($consommes, $seuil);
    }

    public function enEssai(): bool
    {
        return $this->consommes < $this->seuil;
    }

    public function seuilAtteint(): bool
    {
        return $this->consommes >= $this->seuil;
    }

    public function rapportsConsommes(): int
    {
        return $this->consommes;
    }

    public function rapportsRestants(): int
    {
        return max(0, $this->seuil - $this->consommes);
    }

    public function seuil(): int
    {
        return $this->seuil;
    }
}
