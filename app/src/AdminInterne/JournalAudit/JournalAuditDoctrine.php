<?php

declare(strict_types=1);

namespace App\AdminInterne\JournalAudit;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Journal d'audit persisté (#50) : append-only, une ligne par événement.
 *
 * @extends ServiceEntityRepository<EvenementAudit>
 */
class JournalAuditDoctrine extends ServiceEntityRepository implements JournalAudit
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvenementAudit::class);
    }

    public function enregistrer(EvenementAudit $evenement): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($evenement);
        $manager->flush();
    }

    public function evenementsRecents(int $limite = 50): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.survenuLe', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
