<?php

declare(strict_types=1);

namespace App\AdminInterne\JournalAudit;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste du journal d'audit (#50) : append-only, les plus récents
 * d'abord par défaut. Recherche sur acteur/action/cible ; aucune action de groupe
 * (le registre est intangible).
 */
#[AsListeSource('journal')]
final class SourceJournal implements SourceListe
{
    private const PLAFOND = 2000;

    public function __construct(
        private readonly JournalAudit $journal,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->journal->evenementsRecents(self::PLAFOND),
            $this->colonnes(),
            [
                'date' => static fn (EvenementAudit $e): int => $e->survenuLe()->getTimestamp(),
                'acteur' => static fn (EvenementAudit $e): string => $e->acteur(),
                'action' => static fn (EvenementAudit $e): string => $e->action(),
            ],
            [
                static fn (EvenementAudit $e): string => $e->acteur(),
                static fn (EvenementAudit $e): string => $e->action(),
                static fn (EvenementAudit $e): string => $e->cible(),
            ],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'acteur', 'action');
    }
}
