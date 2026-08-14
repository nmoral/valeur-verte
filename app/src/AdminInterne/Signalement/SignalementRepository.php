<?php

declare(strict_types=1);

namespace App\AdminInterne\Signalement;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Journal des retours « ce chiffre me semble faux » (#49) : append-only, une
 * ligne par signalement.
 *
 * @extends ServiceEntityRepository<Signalement>
 */
class SignalementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Signalement::class);
    }

    public function enregistrer(Signalement $signalement): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($signalement);
        $manager->flush();
    }

    /**
     * Les signalements les plus récents d'abord, bornés par $limite.
     *
     * @return list<Signalement>
     */
    public function recents(int $limite = 100): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.signaleLe', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
