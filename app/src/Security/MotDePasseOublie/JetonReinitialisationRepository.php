<?php

declare(strict_types=1);

namespace App\Security\MotDePasseOublie;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JetonReinitialisation>
 */
class JetonReinitialisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JetonReinitialisation::class);
    }

    public function enregistrer(JetonReinitialisation $jeton): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($jeton);
        $manager->flush();
    }
}
