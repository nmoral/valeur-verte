<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des millésimes en revue (REG-2, #130) : triable (millésime,
 * effectifs), recherchable (millésime, état). Matérialisée en mémoire.
 */
#[AsListeSource('millesimes')]
final class SourceMillesimes implements SourceListe
{
    public function __construct(
        private readonly LectureMillesimes $lecture,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->lecture->tous(),
            $this->colonnes(),
            [
                'millesime' => static fn (MillesimeEnRevue $m): int => $m->millesime(),
                'calculees' => static fn (MillesimeEnRevue $m): int => $m->maillesCalculees(),
                'bloquees' => static fn (MillesimeEnRevue $m): int => $m->maillesBloquees(),
                'alerte' => static fn (MillesimeEnRevue $m): int => $m->maillesEnAlerte(),
            ],
            [
                static fn (MillesimeEnRevue $m): string => (string) $m->millesime(),
                static fn (MillesimeEnRevue $m): string => $m->etat() ?? '',
            ],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('millesime', 'calculees', 'bloquees', 'alerte');
    }
}
