<?php

declare(strict_types=1);

namespace App\Simulateur;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Utilisation>
 */
class UtilisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisation::class);
    }

    public function enregistrer(Utilisation $utilisation): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($utilisation);
        $manager->flush();
    }

    public function compter(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
