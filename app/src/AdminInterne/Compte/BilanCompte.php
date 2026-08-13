<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

/**
 * Ligne de la vue de pilotage des comptes (#47) : identité du compte, date de
 * création, et statut d'essai (qui porte le compteur de rapports consommés).
 */
final readonly class BilanCompte
{
    public function __construct(
        private int $tenantId,
        private string $identite,
        private \DateTimeImmutable $creeLe,
        private StatutEssai $statut,
    ) {
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function identite(): string
    {
        return $this->identite;
    }

    public function creeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function statut(): StatutEssai
    {
        return $this->statut;
    }

    public function rapportsConsommes(): int
    {
        return $this->statut->rapportsConsommes();
    }
}
