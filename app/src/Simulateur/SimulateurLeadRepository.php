<?php

declare(strict_types=1);

namespace App\Simulateur;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SimulateurLead>
 */
class SimulateurLeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SimulateurLead::class);
    }

    public function enregistrer(SimulateurLead $lead): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($lead);
        $manager->flush();
    }

    public function compter(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
