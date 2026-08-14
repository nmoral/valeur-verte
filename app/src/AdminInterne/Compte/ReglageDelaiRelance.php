<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Compte\DelaiRelanceEssai;
use App\Compte\DelaiRelanceEssaiRepository;
use Psr\Clock\ClockInterface;

/**
 * Réglage du délai de relance d'essai depuis l'admin (#108) : met à jour la source
 * unique et trace le changement au journal d'audit (#50). Un délai invalide est
 * rejeté avant toute écriture.
 */
final readonly class ReglageDelaiRelance
{
    public function __construct(
        private DelaiRelanceEssaiRepository $delais,
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    public function regler(int $jours, string $auteur): void
    {
        $nouveau = DelaiRelanceEssai::de($jours);
        $ancien = $this->delais->courant()->jours();

        $this->delais->enregistrer($nouveau);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'delai_relance_essai.modifie',
            cible: 'delai_relance_essai',
            contexte: ['ancien' => $ancien, 'nouveau' => $jours],
            survenuLe: $this->clock->now(),
        ));
    }
}
