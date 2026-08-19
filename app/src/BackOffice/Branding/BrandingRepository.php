<?php

declare(strict_types=1);

namespace App\BackOffice\Branding;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Branding>
 */
class BrandingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Branding::class);
    }

    /**
     * Marque courante du pro, ou la marque par défaut tant qu'il n'a rien configuré.
     */
    public function courant(): Branding
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? Branding::parDefaut();
    }

    /**
     * Marque d'un tenant désigné explicitement, ou la marque par défaut à défaut.
     *
     * Nécessaire sur la page publique (#40) : le cloisonnement y est éteint (pas de
     * pro connecté), `courant()` renverrait la première marque tous tenants confondus.
     */
    public function pourTenant(int $tenantId): Branding
    {
        return $this->findOneBy(['tenantId' => $tenantId]) ?? Branding::parDefaut();
    }

    /**
     * Matérialise la marque par défaut pour un tenant, tenant attribué
     * **explicitement** : au provisionnement d'un compte, le contexte tenant n'est
     * pas encore posé (cf. EnregistreurFicheEntreprise).
     */
    public function provisionner(int $tenantId): void
    {
        $branding = Branding::parDefaut();
        $branding->setTenantId($tenantId);
        $manager = $this->getEntityManager();
        $manager->persist($branding);
        $manager->flush();
    }

    public function enregistrer(Branding $branding): void
    {
        // La marque est une configuration unique : on remplace la précédente
        // plutôt que d'empiler des lignes (sinon « courant() » garderait la 1re).
        $manager = $this->getEntityManager();
        foreach ($this->findAll() as $ancienne) {
            $manager->remove($ancienne);
        }
        $manager->persist($branding);
        $manager->flush();
    }
}
