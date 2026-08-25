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
        private bool $suspendu = false,
        private ?\DateTimeImmutable $recreeApresEffacementLe = null,
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

    /**
     * Le compte est-il suspendu par l'admin (#47/#322) ? Pilote le badge de la
     * liste : l'éditeur voit d'un coup d'œil ce qu'il a suspendu.
     */
    public function suspendu(): bool
    {
        return $this->suspendu;
    }

    /**
     * Date de recréation d'un compte né après un effacement (#332), nulle sinon :
     * l'essai neutralisé signale une réinscription. Sert à l'éditeur pour trancher
     * un cas particulier — par exemple rétablir l'essai d'un regret légitime.
     */
    public function recreeApresEffacementLe(): ?\DateTimeImmutable
    {
        return $this->recreeApresEffacementLe;
    }
}
