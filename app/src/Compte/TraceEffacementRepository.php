<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TraceEffacement>
 */
class TraceEffacementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TraceEffacement::class);
    }

    /**
     * Écrit la trace de manière **idempotente** sur l'empreinte : une adresse
     * effacée puis réinscrite et ré-effacée met à jour sa trace (date, état
     * d'essai consommé) au lieu de heurter l'unicité. En SQL direct pour rester
     * dans la transaction d'effacement sans dépendre du cycle de flush de l'ORM.
     */
    public function enregistrer(TraceEffacement $trace): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO trace_effacement (empreinte, efface_le, rapports_consommes, fin_essai)
                VALUES (:empreinte, :efface_le, :rapports_consommes, :fin_essai)
                ON CONFLICT (empreinte) DO UPDATE SET
                    efface_le = EXCLUDED.efface_le,
                    rapports_consommes = EXCLUDED.rapports_consommes,
                    fin_essai = EXCLUDED.fin_essai
                SQL,
            [
                'empreinte' => $trace->empreinte(),
                'efface_le' => $trace->effaceLe()->format('Y-m-d H:i:s'),
                'rapports_consommes' => $trace->rapportsConsommes(),
                'fin_essai' => $trace->finEssai()?->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function parEmpreinte(string $empreinte): ?TraceEffacement
    {
        return $this->findOneBy(['empreinte' => $empreinte]);
    }

    /**
     * Purge les traces plus anciennes que la borne (#332, rétention bornée) :
     * l'empreinte ne survit que le temps où l'anti-abus reste proportionné.
     * Renvoie le nombre supprimé.
     */
    public function supprimerAnterieuresA(\DateTimeImmutable $borne): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.effaceLe < :borne')
            ->setParameter('borne', $borne)
            ->getQuery()
            ->execute();
    }
}
