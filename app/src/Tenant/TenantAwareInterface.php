<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Une entité de données pro : cloisonnée par tenant (D3). Le filtre Doctrine
 * « tenant » restreint automatiquement toute requête sur ces entités au tenant
 * courant, et l'attribution du tenant se fait à la persistance.
 */
interface TenantAwareInterface
{
    public function getTenantId(): ?int;

    public function setTenantId(int $tenantId): void;
}
