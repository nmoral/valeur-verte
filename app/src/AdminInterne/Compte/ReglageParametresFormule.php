<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Compte\Formule;
use App\Compte\ParametresFormule;
use App\Compte\ReglagesFormuleRepository;
use Psr\Clock\ClockInterface;

/**
 * Réglage des paramètres d'une formule depuis l'admin (#61/#65) : durée d'essai,
 * quota de rapports, plafond de comptes (0 = illimité). Met à jour la source
 * unique et trace le changement au journal d'audit (#50). Remplace l'ancien seuil
 * d'essai global, désormais porté par le quota de la formule Classique.
 */
final readonly class ReglageParametresFormule
{
    public function __construct(
        private ReglagesFormuleRepository $reglages,
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    public function regler(Formule $formule, int $dureeEssaiJours, int $quotaRapports, int $plafondComptes, string $auteur): void
    {
        $nouveau = new ParametresFormule($dureeEssaiJours, $quotaRapports, $plafondComptes > 0 ? $plafondComptes : null);
        $ancien = $this->reglages->pour($formule);

        $this->reglages->enregistrer($formule, $nouveau);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'formule.modifiee',
            cible: 'formule.'.$formule->value,
            contexte: [
                'formule' => $formule->value,
                'ancien' => ['duree' => $ancien->dureeEssaiJours(), 'quota' => $ancien->quotaRapports(), 'plafond' => $ancien->plafondComptes()],
                'nouveau' => ['duree' => $dureeEssaiJours, 'quota' => $quotaRapports, 'plafond' => $nouveau->plafondComptes()],
            ],
            survenuLe: $this->clock->now(),
        ));
    }
}
