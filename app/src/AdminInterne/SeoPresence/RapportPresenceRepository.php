<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux snapshots de présence externe (#346). Écriture par le batch
 * `app:seo:presence:fetch`, lecture par la section « présence externe » de l'admin `/seo`.
 *
 * @extends ServiceEntityRepository<RapportPresence>
 */
class RapportPresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RapportPresence::class);
    }

    public function enregistrer(RapportPresence $rapport): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($rapport);
        $manager->flush();
    }

    public function dernier(): ?RapportPresence
    {
        return $this->findOneBy([], ['executeLe' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return list<RapportPresence>
     */
    public function recents(int $limite): array
    {
        return $this->findBy([], ['executeLe' => 'DESC', 'id' => 'DESC'], $limite);
    }

    /**
     * Les `$limite` rapports les plus récents, rendus du plus ancien au plus récent
     * (courbe de tendance lue de gauche à droite).
     *
     * @return list<RapportPresence>
     */
    public function tendance(int $limite): array
    {
        return array_reverse($this->recents($limite));
    }
}
