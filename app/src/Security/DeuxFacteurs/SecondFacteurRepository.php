<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecondFacteur>
 */
class SecondFacteurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecondFacteur::class);
    }

    public function enregistrer(SecondFacteur $facteur): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($facteur);
        $manager->flush();
    }

    public function pourUtilisateur(int $userId): ?SecondFacteur
    {
        return $this->findOneBy(['userId' => $userId]);
    }
}
