<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use Psr\Clock\ClockInterface;

/**
 * Réglage des seuils de supervision depuis l'admin (#45) : met à jour la source
 * unique et trace le changement au journal d'audit (#50). Des seuils invalides
 * sont rejetés avant toute écriture.
 */
final readonly class ReglageSeuilsSupervision
{
    public function __construct(
        private SeuilsSupervisionRepository $seuils,
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    public function regler(float $erreurMedianeMaxPct, float $gainEcMaxLog, string $auteur): void
    {
        $nouveaux = SeuilsSupervision::de($erreurMedianeMaxPct, $gainEcMaxLog);
        $anciens = $this->seuils->courants();

        $this->seuils->enregistrer($nouveaux);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'seuils_supervision.modifie',
            cible: 'seuils_supervision',
            contexte: [
                'ancien' => [
                    'erreur_mediane_max_pct' => $anciens->erreurMedianeMaxPct(),
                    'gain_ec_max_log' => $anciens->gainEcMaxLog(),
                ],
                'nouveau' => [
                    'erreur_mediane_max_pct' => $erreurMedianeMaxPct,
                    'gain_ec_max_log' => $gainEcMaxLog,
                ],
            ],
            survenuLe: $this->clock->now(),
        ));
    }
}
