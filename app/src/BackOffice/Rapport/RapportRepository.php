<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rapport>
 */
class RapportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rapport::class);
    }

    public function enregistrer(Rapport $rapport): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($rapport);
        $manager->flush();
    }

    public function parReference(string $reference): ?Rapport
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * Supprime en masse les rapports du tenant courant dont l'id est fourni.
     * Le tenant est contraint explicitement : le filtre SQL multi-tenant ne
     * s'applique qu'aux SELECT, pas au DELETE DQL — un pro ne peut donc effacer
     * que ses propres rapports. Renvoie le nombre supprimé.
     *
     * @param list<int> $ids
     */
    public function supprimerParIds(array $ids, int $tenantId): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->getEntityManager()
            ->createQuery('DELETE '.Rapport::class.' r WHERE r.id IN (:ids) AND r.tenantId = :tenant')
            ->setParameter('ids', $ids)
            ->setParameter('tenant', $tenantId)
            ->execute();
    }

    /**
     * Liste unique des rapports et leads, les plus récents d'abord (§9.2).
     *
     * @return list<Rapport>
     */
    public function recents(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.creeLe', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
