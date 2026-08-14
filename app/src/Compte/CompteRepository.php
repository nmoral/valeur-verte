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

    public function enregistrer(Compte $compte): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($compte);
        $manager->flush();
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
