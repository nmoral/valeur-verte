<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TraceEffacement>
 */
class TraceEffacementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TraceEffacement::class);
    }

    public function enregistrer(TraceEffacement $trace): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($trace);
        $manager->flush();
    }

    public function parEmpreinte(string $empreinte): ?TraceEffacement
    {
        return $this->findOneBy(['empreinte' => $empreinte]);
    }
}
