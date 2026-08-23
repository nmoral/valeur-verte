<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entreprise>
 */
class EntrepriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entreprise::class);
    }

    public function enregistrer(Entreprise $entreprise): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($entreprise);
        $manager->flush();
    }

    /**
     * Supprime la fiche entreprise d'un tenant (#308, effacement de compte). Le
     * DELETE DQL n'est pas filtré par le cloisonnement : le tenant est contraint
     * explicitement. Renvoie le nombre supprimé.
     */
    public function supprimerParTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->execute();
    }
}
