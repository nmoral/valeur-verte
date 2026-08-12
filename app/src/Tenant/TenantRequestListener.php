<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Security\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Active le cloisonnement par défaut : dès qu'un pro est connecté, le filtre
 * « tenant » est activé sur son tenant pour toute la requête. Priorité inférieure
 * à celle du firewall (8) pour disposer du jeton d'authentification.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class TenantRequestListener
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $context,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $tenantId = $user->getTenantId();
        if (null === $tenantId) {
            return;
        }

        $this->context->definir($tenantId);
        $this->entityManager->getFilters()
            ->enable('tenant')
            ->setParameter('tenant_id', $tenantId, Types::INTEGER);
    }
}
