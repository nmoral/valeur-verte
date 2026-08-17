<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use App\Tenant\TenantContext;

/**
 * Action de groupe : supprime les rapports sélectionnés du tenant courant. La
 * suppression est strictement cloisonnée — le repository contraint le tenant sur
 * le DELETE (le filtre SQL ne couvre que les SELECT).
 */
#[AsActionGroupee('rapports.supprimer')]
final readonly class SupprimerRapports implements ActionGroupee
{
    public function __construct(
        private RapportRepository $repository,
        private TenantContext $tenant,
    ) {
    }

    public function executer(array $identifiants): string
    {
        $tenantId = $this->tenant->actuel();
        if (null === $tenantId) {
            return 'Aucun espace courant : suppression annulée.';
        }

        $ids = array_values(array_filter(
            array_map(static fn (string $id): int => (int) $id, $identifiants),
            static fn (int $id): bool => $id > 0,
        ));

        $nombre = $this->repository->supprimerParIds($ids, $tenantId);

        return \sprintf('%d rapport(s) supprimé(s).', $nombre);
    }
}
