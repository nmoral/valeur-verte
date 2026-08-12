<?php

declare(strict_types=1);

namespace App\BackOffice\Branding;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Branding>
 */
class BrandingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Branding::class);
    }

    /**
     * Marque courante du pro, ou la marque par défaut tant qu'il n'a rien configuré.
     */
    public function courant(): Branding
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? Branding::parDefaut();
    }

    public function enregistrer(Branding $branding): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($branding);
        $manager->flush();
    }
}
