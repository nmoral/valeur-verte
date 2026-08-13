<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\Tenant\TenantRepository;
use Doctrine\DBAL\Connection;

/**
 * Vue de pilotage des comptes pro côté éditeur (#47) : pour chaque compte
 * (tenant), assemble l'identité, le compteur de rapports consommés (source
 * unique) et le statut d'essai dérivé du seuil courant. Lecture seule.
 *
 * Les tenants ne sont pas filtrés (l'entité tenant n'est pas tenant-aware) ;
 * les identités d'entreprise sont lues en DBAL, hors du filtre multi-tenant.
 */
final readonly class SupervisionComptes
{
    public function __construct(
        private TenantRepository $tenants,
        private CompteurRapportsConsommes $compteur,
        private SeuilEssaiRepository $seuils,
        private Connection $connexion,
    ) {
    }

    /**
     * @return list<BilanCompte>
     */
    public function comptes(): array
    {
        $seuil = $this->seuils->courant()->valeur();
        $identites = $this->identitesParTenant();

        $comptes = [];
        foreach ($this->tenants->findBy([], ['id' => 'ASC']) as $tenant) {
            $tenantId = $tenant->id();
            if (null === $tenantId) {
                continue;
            }

            $comptes[] = new BilanCompte(
                tenantId: $tenantId,
                identite: $identites[$tenantId] ?? 'Compte #'.$tenantId,
                creeLe: $tenant->creeLe(),
                statut: StatutEssai::depuis($this->compteur->pour($tenantId), $seuil),
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
