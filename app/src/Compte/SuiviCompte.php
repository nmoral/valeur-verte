<?php

declare(strict_types=1);

namespace App\Compte;

use App\AdminInterne\Compte\CompteurRapportsConsommes;
use App\Tenant\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Suivi du compte courant côté artisan (#108) : état d'essai (rapports/jours
 * restants, dérivé de la formule) et bilan d'usage (rapports générés, contacts
 * capturés). Le tenant courant vient du contexte ; un compte non frappé (aucun
 * agrégat) ne donne aucun suivi (pas de bandeau).
 */
final readonly class SuiviCompte
{
    public function __construct(
        private CompteRepository $comptes,
        private ReglagesFormuleRepository $reglages,
        private CompteurRapportsConsommes $compteur,
        private TenantContext $tenant,
        private ClockInterface $horloge,
    ) {
    }

    public function essaiCourant(): ?EtatEssai
    {
        $compte = $this->compteCourant();
        if (null === $compte) {
            return null;
        }

        return EtatEssai::calculer(
            $this->compteur->pour($compte->tenantId()),
            $this->reglages->pour($compte->formule()),
            $compte->creeLe(),
            $this->horloge->now(),
        );
    }

    public function bilanCourant(): ?BilanUsage
    {
        $compte = $this->compteCourant();
        if (null === $compte) {
            return null;
        }

        return new BilanUsage(
            $this->compteur->pour($compte->tenantId()),
            $this->compteur->contactsCaptures($compte->tenantId()),
        );
    }

    /**
     * Le compte courant est-il suspendu (#47/#322) ? Faux s'il n'y a pas de compte
     * courant (admin sans tenant, ou compte non frappé) : la suspension ne
     * concerne qu'un compte pro existant.
     */
    public function estSuspendu(): bool
    {
        return $this->compteCourant()?->estSuspendu() ?? false;
    }

    private function compteCourant(): ?Compte
    {
        $tenantId = $this->tenant->actuel();
        if (null === $tenantId) {
            return null;
        }

        return $this->comptes->pourTenant($tenantId);
    }
}
