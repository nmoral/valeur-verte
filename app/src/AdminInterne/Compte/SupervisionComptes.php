<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\Compte\CompteRepository;
use App\Compte\EtatEssai;
use App\Compte\Formule;
use App\Compte\ReglagesFormuleRepository;
use App\Tenant\TenantRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Vue de pilotage des comptes pro côté éditeur (#47) : pour chaque compte (tenant),
 * assemble l'identité, la formule et l'état d'essai (rapports/jours restants, temps
 * ET quota) — le même modèle que le suivi artisan (#108), source unique. Un tenant
 * sans agrégat compte est traité en Classique par défaut. Lecture seule.
 */
final readonly class SupervisionComptes
{
    public function __construct(
        private TenantRepository $tenants,
        private CompteRepository $comptesPro,
        private CompteurRapportsConsommes $compteur,
        private ReglagesFormuleRepository $reglages,
        private ClockInterface $horloge,
        private Connection $connexion,
    ) {
    }

    /**
     * @return list<BilanCompte>
     */
    public function comptes(): array
    {
        $identites = $this->identitesParTenant();
        $maintenant = $this->horloge->now();

        $comptes = [];
        foreach ($this->tenants->findBy([], ['id' => 'ASC']) as $tenant) {
            $tenantId = $tenant->id();
            if (null === $tenantId) {
                continue;
            }

            $compte = $this->comptesPro->pourTenant($tenantId);
            $formule = $compte?->formule() ?? Formule::Classique;
            $creeLe = $compte?->creeLe() ?? $tenant->creeLe();

            $comptes[] = new BilanCompte(
                tenantId: $tenantId,
                identite: $identites[$tenantId] ?? 'Compte #'.$tenantId,
                creeLe: $tenant->creeLe(),
                formule: $formule,
                essai: null !== $compte
                    ? $compte->etatEssai($this->reglages->pour($formule), $this->compteur->pour($tenantId), $maintenant)
                    : EtatEssai::calculer($this->compteur->pour($tenantId), $this->reglages->pour($formule), $creeLe, $maintenant),
                suspendu: $compte?->estSuspendu() ?? false,
                recreeApresEffacementLe: $compte?->essaiNeutraliseLe(),
            );
        }

        return $comptes;
    }

    /**
     * @return array<int, string>
     */
    private function identitesParTenant(): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT tenant_id, raison_sociale FROM entreprise WHERE tenant_id IS NOT NULL',
        );

        $identites = [];
        foreach ($lignes as $ligne) {
            $tenantId = $ligne['tenant_id'];
            $raisonSociale = $ligne['raison_sociale'];
            if (is_numeric($tenantId) && \is_string($raisonSociale)) {
                $identites[(int) $tenantId] = $raisonSociale;
            }
        }

        return $identites;
    }
}
