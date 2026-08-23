<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Comptes pro. Le compte est frappé à l'inscription et relu par tenant pour la
 * restitution d'essai côté artisan (#108) comme pour la supervision côté éditeur
 * (#47). Non filtré par le cloisonnement : il porte lui-même le tenant.
 *
 * @extends ServiceEntityRepository<Compte>
 */
class CompteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Compte::class);
    }

    public function pourTenant(int $tenantId): ?Compte
    {
        return $this->findOneBy(['tenantId' => $tenantId]);
    }

    /**
     * Comptes candidats fondateurs en attente de validation (#61), les plus
     * anciens d'abord — l'éditeur les traite dans l'ordre d'arrivée.
     *
     * @return list<Compte>
     */
    public function enAttenteDeValidation(): array
    {
        return $this->findBy(
            ['candidatureFondateur' => StatutCandidatureFondateur::EnAttente],
            ['creeLe' => 'ASC', 'id' => 'ASC'],
        );
    }

    /**
     * Comptes n'ayant pas encore reçu leur email de relance de fin d'essai (#108).
     * Le service de relance filtre ensuite ceux dont l'échéance approche.
     *
     * @return list<Compte>
     */
    public function aRelancer(): array
    {
        return $this->findBy(['relanceEssaiEnvoyeeLe' => null], ['id' => 'ASC']);
    }

    public function enregistrer(Compte $compte): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($compte);
        $manager->flush();
    }

    /**
     * Comptes dont la demande d'effacement a dépassé le délai de grâce (#308) :
     * `suppression_demandee_le` non nulle et antérieure ou égale à la borne. Les
     * plus anciens d'abord. Sert au finaliseur (cron).
     *
     * @return list<Compte>
     */
    public function exigiblesPourEffacement(\DateTimeImmutable $borne): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.suppressionDemandeeLe IS NOT NULL')
            ->andWhere('c.suppressionDemandeeLe <= :borne')
            ->setParameter('borne', $borne)
            ->orderBy('c.suppressionDemandeeLe', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime le compte d'un tenant (#308, effacement). Renvoie le nombre
     * supprimé (0 ou 1, le tenant étant unique).
     */
    public function supprimerParTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->execute();
    }

    /**
     * Nombre de comptes déjà passés fondateurs — sert à faire respecter le plafond
     * de la formule avant une validation (#61).
     */
    public function nombreDeFondateurs(): int
    {
        return (int) $this->count(['formule' => Formule::Fondateur]);
    }
}
