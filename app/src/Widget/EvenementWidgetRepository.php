<?php

declare(strict_types=1);

namespace App\Widget;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvenementWidget>
 */
class EvenementWidgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvenementWidget::class);
    }

    public function enregistrer(EvenementWidget $evenement): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($evenement);
        $manager->flush();
    }

    /**
     * Supprime les événements survenus avant la borne (rétention courte, #90).
     *
     * @return int nombre d'événements supprimés
     */
    public function purgerAnterieursA(\DateTimeImmutable $limite): int
    {
        return $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.survenuLe < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }

    /**
     * Les étapes d'une même visite, dans l'ordre où elles ont été franchies.
     *
     * @return list<EvenementWidget>
     */
    public function pourSession(string $sessionId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.sessionId = :session')
            ->setParameter('session', $sessionId)
            ->orderBy('e.survenuLe', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
