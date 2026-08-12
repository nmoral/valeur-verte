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
}
