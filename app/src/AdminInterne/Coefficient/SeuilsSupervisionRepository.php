<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Réglage unique des seuils de supervision (#45) : source de vérité éditable
 * dans l'admin.
 *
 * @extends ServiceEntityRepository<SeuilsSupervision>
 */
class SeuilsSupervisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeuilsSupervision::class);
    }

    /**
     * Seuils configurés, ou les défauts tant que rien n'a été réglé.
     */
    public function courants(): SeuilsSupervision
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? SeuilsSupervision::parDefaut();
    }

    public function enregistrer(SeuilsSupervision $seuils): void
    {
        // Réglage unique : on remplace la valeur précédente plutôt que d'empiler.
        $manager = $this->getEntityManager();
        foreach ($this->findAll() as $anciens) {
            $manager->remove($anciens);
        }
        $manager->persist($seuils);
        $manager->flush();
    }
}
