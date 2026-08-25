<?php

declare(strict_types=1);

namespace App\Onboarding\Parcours;

use Symfony\Component\Clock\ClockInterface;

/**
 * Purge des événements du parcours d'inscription passé le délai de rétention
 * (#111). La collecte anonyme est déclarée pour une finalité de mesure de
 * l'activation : elle ne se conserve pas indéfiniment. La purge est le pendant
 * obligatoire de la collecte — sans elle, la rétention « courte » annoncée
 * n'existe pas. Idempotente : à jouer par un cron (sur le VPS, registre #190).
 */
final readonly class PurgeEvenementsInscription
{
    public function __construct(
        private EvenementInscriptionRepository $evenements,
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
