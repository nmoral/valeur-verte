<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux snapshots d'audit SEO (#345). Écriture par le batch `app:seo:audit`,
 * lecture par la page d'admin interne `/seo` (dernier rapport, liste, tendance).
 *
 * @extends ServiceEntityRepository<RapportSeo>
 */
class RapportSeoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RapportSeo::class);
    }

    public function enregistrer(RapportSeo $rapport): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($rapport);
        $manager->flush();
    }

    public function dernier(): ?RapportSeo
    {
        return $this->findOneBy([], ['executeLe' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Les `$limite` rapports les plus récents, rendus **du plus ancien au plus
     * récent** : la restitution en tire une courbe de tendance qui se lit de gauche
     * à droite dans le sens du temps.
     *
     * @return list<RapportSeo>
     */
    public function tendance(int $limite): array
    {
        $recents = $this->findBy([], ['executeLe' => 'DESC', 'id' => 'DESC'], $limite);

        return array_reverse($recents);
    }
}
