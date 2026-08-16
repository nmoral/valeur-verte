<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Validation d'une candidature fondateur par l'éditeur (#61) : après contact, il
 * valide (bascule en Fondateur) ou refuse (reste Classique). La validation
 * respecte le plafond de comptes fondateurs de la formule (source éditable).
 */
final readonly class ValidationCandidatureFondateur
{
    public function __construct(
        private CompteRepository $comptes,
        private ReglagesFormuleRepository $reglages,
    ) {
    }

    public function valider(int $tenantId): void
    {
        $compte = $this->comptes->pourTenant($tenantId);
        if (null === $compte) {
            return;
        }

        $plafond = $this->reglages->pour(Formule::Fondateur)->plafondComptes();
        if (null !== $plafond && $this->comptes->nombreDeFondateurs() >= $plafond) {
            throw new PlafondFondateursAtteint($plafond);
        }

        $compte->validerCommeFondateur();
        $this->comptes->enregistrer($compte);
    }

    public function refuser(int $tenantId): void
    {
        $compte = $this->comptes->pourTenant($tenantId);
        if (null === $compte) {
            return;
        }

        $compte->refuserFondateur();
        $this->comptes->enregistrer($compte);
    }
}
