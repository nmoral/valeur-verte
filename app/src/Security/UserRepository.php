<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function enregistrer(User $user): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($user);
        $manager->flush();
    }

    /**
     * Supprime les utilisateurs rattachés à un tenant (#308, effacement de
     * compte) : un compte pourra porter plusieurs licences, toutes partent avec
     * lui. Renvoie le nombre supprimé.
     */
    public function supprimerParTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('u')
            ->delete()
            ->andWhere('u.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->execute();
    }
}
