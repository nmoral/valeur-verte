<?php

declare(strict_types=1);

namespace App\Compte;

use Psr\Clock\ClockInterface;

/**
 * Purge des traces d'effacement (#332) : une empreinte n'est conservée que le
 * temps borné de la rétention (12 mois par défaut), au-delà duquel priver un
 * compte recréé de son essai ne serait plus proportionné (RGPD). Jouée par un
 * cron quotidien (sur le VPS, §5 ; registre #190), idempotente et rejouable.
 */
final readonly class PurgeTracesEffacement
{
    public function __construct(
        private TraceEffacementRepository $traces,
        private ClockInterface $horloge,
        private int $retentionMois,
    ) {
    }

    public function executer(): int
    {
        $borne = $this->horloge->now()->sub(new \DateInterval('P'.$this->retentionMois.'M'));

        return $this->traces->supprimerAnterieuresA($borne);
    }
}
