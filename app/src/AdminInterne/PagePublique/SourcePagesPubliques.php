<?php

declare(strict_types=1);

namespace App\AdminInterne\PagePublique;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des pages publiques (#268) : la supervision matérialise les états
 * de service ; on trie/cherche/pagine en mémoire — le nombre de pages est celui des
 * pros, pas celui des visiteurs.
 */
#[AsListeSource('pages_publiques')]
final class SourcePagesPubliques implements SourceListe
{
    public function __construct(
        private readonly SupervisionPagesPubliques $supervision,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->supervision->pages(),
            $this->colonnes(),
            [
                'page' => static fn (PagePubliqueSupervisee $p): string => $p->slug(),
                'etat' => static fn (PagePubliqueSupervisee $p): string => $p->etatDeService(),
                'alerte' => static fn (PagePubliqueSupervisee $p): int => $p->alerteeLe()?->getTimestamp() ?? 0,
            ],
            [
                static fn (PagePubliqueSupervisee $p): string => $p->slug(),
                static fn (PagePubliqueSupervisee $p): string => $p->token(),
            ],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('page', 'etat', 'alerte');
    }
}
