<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\Compte\EtatEssai;
use App\Compte\Formule;

/**
 * Ligne de la vue de pilotage des comptes (#47) : identité du compte, date de
 * création, formule et état d'essai (rapports/jours restants, temps ET quota).
 * Restitution côté éditeur du même modèle que le suivi artisan (#108).
 */
final readonly class BilanCompte
{
    public function __construct(
        private int $tenantId,
        private string $identite,
        private \DateTimeImmutable $creeLe,
        private Formule $formule,
        private EtatEssai $essai,
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

    public function formule(): Formule
    {
        return $this->formule;
    }

    public function essai(): EtatEssai
    {
        return $this->essai;
    }
}
