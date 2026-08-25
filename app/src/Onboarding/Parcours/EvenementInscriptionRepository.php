<?php

declare(strict_types=1);

namespace App\Onboarding\Parcours;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvenementInscription>
 */
class EvenementInscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvenementInscription::class);
    }

    public function enregistrer(EvenementInscription $evenement): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($evenement);
        $manager->flush();
    }

    /**
     * Nombre de visites distinctes ayant franchi chaque étape (#111). On compte les
     * sessions, pas les événements : un rechargement de l'écran d'inscription ne
     * doit pas gonfler le dénominateur du taux d'abandon.
     *
     * @return array<string, int> valeur du type => nombre de sessions distinctes
     */
    public function compterSessionsParType(): array
    {
        /** @var list<array{type: TypeEvenementInscription, nombre: int}> $lignes */
        $lignes = $this->createQueryBuilder('e')
            ->select('e.type AS type', 'COUNT(DISTINCT e.sessionId) AS nombre')
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
     * Supprime les événements survenus avant la borne (rétention courte, #111).
     *
     * @return int nombre d'événements supprimés
     */
    public function purgerAnterieursA(\DateTimeImmutable $limite): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.survenuLe < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }

    /**
     * Les étapes d'une même visite, dans l'ordre où elles ont été franchies.
     *
     * @return list<EvenementInscription>
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
