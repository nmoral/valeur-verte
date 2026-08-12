<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Attribue le tenant courant à toute entité pro persistée sans tenant explicite.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class AttributionTenantListener
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TenantAwareInterface || null !== $entity->getTenantId()) {
            return;
        }

        $tenantId = $this->context->actuel();
        if (null !== $tenantId) {
            $entity->setTenantId($tenantId);
        }
    }
}
