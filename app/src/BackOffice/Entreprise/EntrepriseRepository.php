<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entreprise>
 */
class EntrepriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entreprise::class);
    }

    /**
     * Fiche du pro courant (#311), ou null s'il n'en a pas — SIRET inconnu de
     * l'API à l'inscription (#106). Le cloisonnement par tenant est porté par le
     * filtre Doctrine : la requête n'a pas à le répéter.
     */
    public function courante(): ?Entreprise
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    public function enregistrer(Entreprise $entreprise): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($entreprise);
        $manager->flush();
    }

    /**
     * Fiche d'un tenant désigné explicitement (#309, export RGPD), ou null : à la
     * différence de `courante()`, ne s'appuie pas sur le filtre du pro connecté —
     * l'export admin lit un tenant tiers.
     */
    public function parTenant(int $tenantId): ?Entreprise
    {
        return $this->findOneBy(['tenantId' => $tenantId]);
    }

    /**
     * Supprime la fiche entreprise d'un tenant (#308, effacement de compte). Le
     * DELETE DQL n'est pas filtré par le cloisonnement : le tenant est contraint
     * explicitement. Renvoie le nombre supprimé.
     */
    public function supprimerParTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->execute();
    }
}
