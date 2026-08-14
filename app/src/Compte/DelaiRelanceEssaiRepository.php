<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Réglage unique du délai de relance d'essai (#108), éditable comme le seuil.
 *
 * @extends ServiceEntityRepository<DelaiRelanceEssai>
 */
class DelaiRelanceEssaiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DelaiRelanceEssai::class);
    }

    public function courant(): DelaiRelanceEssai
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? DelaiRelanceEssai::parDefaut();
    }

    public function enregistrer(DelaiRelanceEssai $delai): void
    {
        // Réglage unique : on remplace la valeur précédente plutôt que d'empiler.
        $manager = $this->getEntityManager();
        foreach ($this->findAll() as $ancien) {
            $manager->remove($ancien);
        }
        $manager->persist($delai);
        $manager->flush();
    }
}
