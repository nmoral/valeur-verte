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
     * Compte, par type, les événements d'une page depuis une borne (#268).
     *
     * C'est la mesure de la fenêtre glissante de l'anti-abus réactif : bornée à une
     * page et à un instant, elle sert aussi bien le trafic légitime (estimations,
     * leads) que les rejets. Les types sans événement sont absents du tableau.
     *
     * @return array<string, int> type de l'événement => nombre sur la fenêtre
     */
    public function compterParTypeDepuis(string $token, \DateTimeImmutable $depuis): array
    {
        /** @var list<array{type: TypeEvenementWidget, nombre: int}> $lignes */
        $lignes = $this->createQueryBuilder('e')
            ->select('e.type AS type', 'COUNT(e.id) AS nombre')
            ->andWhere('e.token = :token')
            ->andWhere('e.survenuLe >= :depuis')
            ->setParameter('token', $token)
            ->setParameter('depuis', $depuis)
            ->groupBy('e.type')
            ->getQuery()
            ->getResult();

        $comptes = [];
        foreach ($lignes as $ligne) {
            $comptes[$ligne['type']->value] = $ligne['nombre'];
        }

        return $comptes;
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
