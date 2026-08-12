<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Cloisonnement multi-tenant (D3) : restreint toute entité TenantAware au tenant
 * courant. Sans paramètre posé, il ne contraint rien (le contexte s'en charge).
 */
final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(TenantAwareInterface::class)) {
            return '';
        }

        try {
            $tenantId = $this->getParameter('tenant_id');
        } catch (\InvalidArgumentException) {
            return '';
        }

        return \sprintf('%s.tenant_id = %s', $targetTableAlias, $tenantId);
    }
}
