<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use Symfony\Component\Clock\ClockInterface;

/**
 * Rétention des rapports (décision D10, #68).
 *
 * Deux échéances, comptées depuis la production du rapport — faute de suivi
 * d'interaction, c'est le dernier contact connu :
 *
 * - **anonymisation** : le contact disparaît, l'adresse tombe à la commune. Le
 *   rapport reste consultable, ses chiffres sont intacts (`CLAUDE.md` §7).
 * - **suppression** : la ligne disparaît.
 *
 * La suppression passe **avant** l'anonymisation : sans quoi un rapport au-delà
 * des deux échéances serait anonymisé puis supprimé dans le même passage, et
 * compté deux fois. Idempotente — `anonymiseLe` garde la trace du travail déjà
 * fait. À jouer par un cron quotidien (sur le VPS, cf. #190).
 *
 * Le balayage est **global**. Joué en CLI, sans requête, le filtre de
 * cloisonnement n'est pas paramétré et ne contraint rien (`TenantFilter`) :
 * c'est voulu, une rétention qui s'arrêterait au tenant courant ne purgerait
 * jamais rien.
 */
final readonly class RetentionRapports
{
    public function __construct(
        private RapportRepository $rapports,
        private ClockInterface $horloge,
        private int $anonymisationMois,
        private int $suppressionMois,
    ) {
    }

    public function executer(): BilanRetention
    {
        $maintenant = $this->horloge->now();

        $supprimes = $this->rapports->supprimerProduitsAvant($maintenant->modify('-'.$this->suppressionMois.' months'));
        $anonymises = $this->rapports->anonymiserProduitsAvant($maintenant->modify('-'.$this->anonymisationMois.' months'), $maintenant);

        return new BilanRetention($anonymises, $supprimes);
    }
}
