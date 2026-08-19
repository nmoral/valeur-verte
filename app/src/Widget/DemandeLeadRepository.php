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
}
