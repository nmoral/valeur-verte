<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\Clock\ClockInterface;

/**
 * Purge des événements de parcours passé le délai de rétention (#90, épic #6).
 *
 * La collecte est déclarée pour une finalité de mesure d'audience et de
 * conversion : elle ne se conserve pas indéfiniment. La purge est le pendant
 * obligatoire de la collecte — sans elle, la rétention « courte » annoncée
 * n'existe pas. Idempotente : à jouer par un cron (sur le VPS, cf. #190).
 */
final readonly class PurgeEvenementsWidget
{
    public function __construct(
        private EvenementWidgetRepository $evenements,
        private ClockInterface $horloge,
        private int $retentionJours,
    ) {
    }

    /**
     * @return int nombre d'événements supprimés
     */
    public function executer(): int
    {
        return $this->evenements->purgerAnterieursA(
            $this->horloge->now()->modify('-'.$this->retentionJours.' days'),
        );
    }
}
