<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Tenant courant de la requête, alimenté depuis l'utilisateur connecté. Sert
 * d'attribution du tenant à la persistance des entités pro.
 */
final class TenantContext
{
    private ?int $tenantId = null;

    public function definir(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function actuel(): ?int
    {
        return $this->tenantId;
    }
}
