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

    /**
     * Supprime l'espace du tenant (#308, effacement de compte) : l'identifiant du
     * tenant est l'`id` de cette entité, que portent toutes les données pro.
     * Renvoie le nombre supprimé (0 ou 1).
     */
    public function supprimerParId(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.id = :id')
            ->setParameter('id', $tenantId)
            ->getQuery()
            ->execute();
    }
}
