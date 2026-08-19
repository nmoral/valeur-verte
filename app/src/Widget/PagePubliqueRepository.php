<?php

declare(strict_types=1);

namespace App\Widget;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PagePublique>
 */
class PagePubliqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PagePublique::class);
    }

    /**
     * Résout une page à partir de son token opaque. Sur le host `widget.`, aucun pro
     * n'est connecté : le filtre de cloisonnement est éteint, la recherche par token
     * (unique) porte donc sur l'ensemble des tenants — c'est l'effet recherché. Le
     * slug de l'URL n'entre pas dans la résolution : il est purement décoratif.
     */
    public function trouverActiveParJeton(string $token): ?PagePublique
    {
        return $this->findOneBy(['token' => $token, 'active' => true]);
    }
}
