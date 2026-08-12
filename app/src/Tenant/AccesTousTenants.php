<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Lève le cloisonnement le temps d'une action (admin interne), puis le rétablit.
 * L'acte est tracé (D3) : il n'y a pas d'accès inter-tenant silencieux.
 */
final class AccesTousTenants
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TraceDesactivationTenant $trace,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    public function executer(string $raison, string $auteur, callable $action): mixed
    {
        $this->trace->tracer($raison, $auteur);

        $filtres = $this->entityManager->getFilters();
        $etaitActif = $filtres->isEnabled('tenant');
        if ($etaitActif) {
            // suspend/restore préserve le paramètre (tenant courant), là où
            // disable/enable recréerait un filtre vierge.
            $filtres->suspend('tenant');
        }

        try {
            return $action();
        } finally {
            if ($etaitActif) {
                $filtres->restore('tenant');
            }
        }
    }
}
