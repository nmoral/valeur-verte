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
     * Supprime tous les rapports d'un tenant (#308, effacement de compte). Le
     * filtre multi-tenant ne portant pas sur le DELETE DQL, le tenant est
     * contraint explicitement. Renvoie le nombre supprimé.
     */
    public function supprimerParTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
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

    /**
     * Anonymise les rapports produits avant la borne et pas encore traités
     * (D10, #68) : contact effacé, adresse bornée à la commune, dans la colonne
     * comme dans l'instantané. Les entités sont chargées — l'écriture passe par
     * le domaine (`Rapport::anonymiser`), pas par un `UPDATE` en aveugle qui ne
     * saurait pas borner une adresse ni toucher le JSONB.
     *
     * @return int nombre de rapports anonymisés
     */
    public function anonymiserProduitsAvant(\DateTimeImmutable $limite, \DateTimeImmutable $quand): int
    {
        $rapports = $this->createQueryBuilder('r')
            ->andWhere('r.creeLe < :limite')
            ->andWhere('r.anonymiseLe IS NULL')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getResult();

        foreach ($rapports as $rapport) {
            $rapport->anonymiser($quand);
        }
        $this->getEntityManager()->flush();

        return \count($rapports);
    }

    /**
     * Supprime les rapports produits avant la borne (D10, #68) : au-delà, plus
     * rien ne justifie de les conserver, anonymisés ou non.
     *
     * @return int nombre de rapports supprimés
     */
    public function supprimerProduitsAvant(\DateTimeImmutable $limite): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.creeLe < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }
}
