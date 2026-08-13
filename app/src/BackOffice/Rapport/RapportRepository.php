<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rapport>
 */
class RapportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rapport::class);
    }

    public function enregistrer(Rapport $rapport): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($rapport);
        $manager->flush();
    }

    public function parReference(string $reference): ?Rapport
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * Liste unique des rapports et leads, les plus récents d'abord (§9.2).
     *
     * @return list<Rapport>
     */
    public function recents(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.creeLe', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
