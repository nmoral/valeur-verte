<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\Clock\ClockInterface;

/**
 * Rétention des demandes de lead du widget (#292, épic #6).
 *
 * Une `DemandeLead` (#42) porte des données nominatives — nom, email, et
 * l'instantané figé qui contient l'adresse du bien. Une demande dont l'opt-in
 * n'a jamais été confirmé ne repose sur aucun consentement confirmé : passé un
 * délai de grâce après son expiration, elle est supprimée. La grâce laisse le
 * temps d'un clic tardif ; au-delà, la donnée n'a plus de raison d'être.
 *
 * Une demande **confirmée** a déjà été matérialisée en Rapport(Source::Widget)
 * (#37) qui porte la même donnée : passé un court délai de garde, la ligne
 * d'origine n'est plus qu'un doublon nominatif. La supprimer ne rouvre aucun
 * rejeu — le jeton est consommé, et une empreinte absente rend le même 404
 * qu'une empreinte inconnue.
 *
 * Idempotente : à jouer par un cron (sur le VPS, cf. #190).
 */
final readonly class PurgeDemandesLeadWidget
{
    public function __construct(
        private DemandeLeadRepository $demandes,
        private ClockInterface $horloge,
        private int $graceJours,
        private int $gardeJours,
    ) {
    }

    /**
     * @return int nombre de demandes supprimées
     */
    public function executer(): int
    {
        $maintenant = $this->horloge->now();

        return $this->demandes->purgerNonConfirmeesExpireesAvant($maintenant->modify('-'.$this->graceJours.' days'))
            + $this->demandes->purgerConfirmeesAvant($maintenant->modify('-'.$this->gardeJours.' days'));
    }
}
