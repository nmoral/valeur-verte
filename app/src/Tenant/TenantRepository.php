<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function nouveau(): Tenant
    {
        $tenant = new Tenant();
        $manager = $this->getEntityManager();
        $manager->persist($tenant);
        $manager->flush();

        return $tenant;
    }
}
