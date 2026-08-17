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
     * Marque comme traités les signalements dont l'id est fourni, sans écraser un
     * traitement déjà posé (append-only sur l'état). Renvoie le nombre modifié.
     *
     * @param list<int> $ids
     */
    public function marquerTraites(array $ids, \DateTimeImmutable $quand): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->getEntityManager()
            ->createQuery('UPDATE '.Signalement::class.' s SET s.traiteLe = :quand WHERE s.id IN (:ids) AND s.traiteLe IS NULL')
            ->setParameter('quand', $quand)
            ->setParameter('ids', $ids)
            ->execute();
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
