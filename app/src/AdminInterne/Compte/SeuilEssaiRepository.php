<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Réglage unique du seuil d'essai (#47) : source de vérité éditable dans l'admin.
 *
 * @extends ServiceEntityRepository<SeuilEssai>
 */
class SeuilEssaiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeuilEssai::class);
    }

    /**
     * Seuil configuré, ou le seuil par défaut tant que rien n'a été réglé.
     */
    public function courant(): SeuilEssai
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? SeuilEssai::parDefaut();
    }

    public function enregistrer(SeuilEssai $seuil): void
    {
        // Réglage unique : on remplace la valeur précédente plutôt que d'empiler
        // des lignes (sinon « courant() » garderait la première).
        $manager = $this->getEntityManager();
        foreach ($this->findAll() as $ancien) {
            $manager->remove($ancien);
        }
        $manager->persist($seuil);
        $manager->flush();
    }
}
