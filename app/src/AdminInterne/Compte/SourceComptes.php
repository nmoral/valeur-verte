<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des comptes pro (#47) : la supervision matérialise les bilans
 * (identité, formule, essai) ; on trie/cherche/pagine en mémoire.
 */
#[AsListeSource('comptes')]
final class SourceComptes implements SourceListe
{
    public function __construct(
        private readonly SupervisionComptes $supervision,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->supervision->comptes(),
            $this->colonnes(),
            [
                'compte' => static fn (BilanCompte $c): string => $c->identite(),
                'date' => static fn (BilanCompte $c): int => $c->creeLe()->getTimestamp(),
                'formule' => static fn (BilanCompte $c): string => $c->formule()->libelle(),
            ],
            [static fn (BilanCompte $c): string => $c->identite()],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'compte', 'formule');
    }
}
