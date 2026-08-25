<?php

declare(strict_types=1);

namespace App\Widget;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeLead>
 */
class DemandeLeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeLead::class);
    }

    public function enregistrer(DemandeLead $demande): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($demande);
        $manager->flush();
    }

    /**
     * Résout une demande par l'empreinte de son jeton. Sur le host `widget.` le
     * cloisonnement tenant est éteint : la recherche par empreinte (unique) porte
     * sur l'ensemble des tenants, comme la résolution de page par jeton.
     */
    public function trouverParEmpreinte(string $empreinte): ?DemandeLead
    {
        return $this->findOneBy(['empreinte' => $empreinte]);
    }

    /**
     * Supprime les demandes jamais confirmées dont l'expiration précède la borne
     * (#292). Une demande encore utilisable — non expirée — n'est jamais touchée :
     * la purge ne casse pas un opt-in en cours.
     *
     * @return int nombre de demandes supprimées
     */
    public function purgerNonConfirmeesExpireesAvant(\DateTimeImmutable $limite): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.utiliseLe IS NULL')
            ->andWhere('d.expireLe < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les demandes confirmées avant la borne (#292) : le lead vit
     * désormais dans son Rapport, la ligne d'origine n'est plus qu'un doublon.
     *
     * @return int nombre de demandes supprimées
     */
    public function purgerConfirmeesAvant(\DateTimeImmutable $limite): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.utiliseLe < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime toutes les demandes de lead d'un tenant (#308, effacement de
     * compte). Le DELETE DQL n'est pas filtré par le cloisonnement : le tenant est
     * contraint explicitement. Renvoie le nombre supprimé.
     */
    public function supprimerParTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->execute();
    }

    /**
     * Tous les leads widget d'un tenant, tenant contraint explicitement (#309,
     * export RGPD) : l'export admin lit un tenant qui n'est pas celui connecté.
     *
     * @return list<DemandeLead>
     */
    public function parTenant(int $tenantId): array
    {
        return $this->findBy(['tenantId' => $tenantId], ['id' => 'ASC']);
    }
}
