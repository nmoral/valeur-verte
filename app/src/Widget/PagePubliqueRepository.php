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
     * Résout une page **quel que soit son état** — retirée par le pro, suspendue
     * (#268). Réservé à l'administration : le visiteur, lui, passe par
     * {@see self::trouverActiveParJeton()}, qui ne rend que ce qui est servi.
     */
    public function trouverParJeton(string $token): ?PagePublique
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function enregistrer(PagePublique $page): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($page);
        $manager->flush();
    }

    /**
     * Résout une page à partir de son token opaque. Sur le host `widget.`, aucun pro
     * n'est connecté : le filtre de cloisonnement est éteint, la recherche par token
     * (unique) porte donc sur l'ensemble des tenants — c'est l'effet recherché. Le
     * slug de l'URL n'entre pas dans la résolution : il est purement décoratif.
     *
     * Les deux verrous doivent être ouverts : l'interrupteur du pro (`active`) et
     * l'absence de suspension admin/automatique (#268).
     */
    public function trouverActiveParJeton(string $token): ?PagePublique
    {
        return $this->findOneBy(['token' => $token, 'active' => true, 'suspendueLe' => null]);
    }

    /**
     * Le jeton est-il déjà porté par une page, **quel que soit son tenant** (#38) ?
     *
     * La lecture passe par DBAL, pas par l'ORM : depuis l'espace pro le filtre de
     * cloisonnement est armé et masquerait la page d'un autre tenant — le jeton
     * passerait pour libre, et l'unicité se ferait trancher à l'insertion par une
     * erreur 500. Une sonde d'unicité n'est pas un accès aux données d'autrui : elle
     * ne rend qu'un booléen sur un jeton qu'on vient de tirer, il n'y a donc rien à
     * tracer au titre de D3.
     */
    public function jetonDejaPris(string $token): bool
    {
        $existe = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM page_publique WHERE token = :token',
            ['token' => $token],
        );

        return false !== $existe;
    }

    /**
     * Page du pro courant, ou `null` s'il n'en a pas encore (compte antérieur à
     * #38). Le cloisonnement fait le tri : depuis l'espace pro, le filtre restreint
     * la recherche au tenant connecté.
     */
    public function courante(): ?PagePublique
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
