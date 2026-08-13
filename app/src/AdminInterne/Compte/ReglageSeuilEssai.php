<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use Psr\Clock\ClockInterface;

/**
 * Réglage du seuil d'essai depuis l'admin (#47) : met à jour la source unique
 * et trace le changement au journal d'audit (#50) — un réglage produit ne change
 * jamais en silence. Un seuil invalide est rejeté avant toute écriture.
 */
final readonly class ReglageSeuilEssai
{
    public function __construct(
        private SeuilEssaiRepository $seuils,
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    public function regler(int $valeur, string $auteur): void
    {
        $nouveau = SeuilEssai::de($valeur);
        $ancien = $this->seuils->courant()->valeur();

        $this->seuils->enregistrer($nouveau);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'seuil_essai.modifie',
            cible: 'seuil_essai',
            contexte: ['ancien' => $ancien, 'nouveau' => $valeur],
            survenuLe: $this->clock->now(),
        ));
    }
}
