<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des mailles supervisées (#45) : la supervision lit et
 * diagnostique les mailles (schéma data, lecture seule) en PHP ; on trie/cherche/
 * pagine en mémoire. Tri par défaut : les signalées en tête (avant affichage client).
 */
#[AsListeSource('coefficients')]
final class SourceCoefficients implements SourceListe
{
    public function __construct(
        private readonly SupervisionCoefficients $supervision,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->supervision->mailles(),
            $this->colonnes(),
            [
                'signalement' => static fn (MailleSupervisee $m): int => $m->estAberrante() ? 1 : 0,
                'code' => static fn (MailleSupervisee $m): string => $m->codeInsee(),
                'millesime' => static fn (MailleSupervisee $m): int => $m->millesime(),
                'n' => static fn (MailleSupervisee $m): int => $m->n(),
                'erreur' => static fn (MailleSupervisee $m): float => $m->erreurMediane(),
                'gain' => static fn (MailleSupervisee $m): float => $m->gainEcLog() ?? -999.0,
            ],
            [static fn (MailleSupervisee $m): string => $m->codeInsee()],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('signalement', 'code', 'millesime', 'n', 'erreur', 'gain');
    }
}
