<?php

declare(strict_types=1);

namespace App\BackOffice\GrilleCout;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * Grille de coûts du pro (#34), cloisonnée par tenant. Tant que le pro n'a rien
 * configuré, la grille nationale par défaut est servie (ONB-3, « un rapport avant
 * configuration »), puis sa grille prend le relais.
 *
 * @extends ServiceEntityRepository<GrilleCoutEnregistree>
 */
class GrilleCoutRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, GrilleCoutEnregistree::class);
    }

    public function courant(): GrilleCout
    {
        $enregistree = $this->findOneBy([], ['valideDu' => 'DESC', 'id' => 'DESC']);

        return $enregistree?->versGrille()
            ?? GrilleCout::parDefautNationale(PeriodeValidite::depuis($this->clock->now()));
    }

    /**
     * Grille réellement enregistrée par un tenant (#309, export RGPD), ou null
     * s'il n'en a pas configuré : à la différence de `courant()`, ne retombe pas
     * sur la grille nationale par défaut — l'export porte ce que le pro possède —
     * et lit un tenant tiers (export admin) sans dépendre du filtre du connecté.
     */
    public function parTenant(int $tenantId): ?GrilleCout
    {
        return $this->findOneBy(['tenantId' => $tenantId], ['valideDu' => 'DESC', 'id' => 'DESC'])?->versGrille();
    }

    /**
     * Matérialise une grille pour un tenant, tenant attribué **explicitement** :
     * au provisionnement d'un compte, le contexte tenant n'est pas encore posé
     * (cf. EnregistreurFicheEntreprise).
     */
    public function provisionner(int $tenantId, GrilleCout $grille): void
    {
        $enregistree = GrilleCoutEnregistree::depuis($grille);
        $enregistree->setTenantId($tenantId);
        $manager = $this->getEntityManager();
        $manager->persist($enregistree);
        $manager->flush();
    }
}
