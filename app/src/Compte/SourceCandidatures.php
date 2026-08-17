<?php

declare(strict_types=1);

namespace App\Compte;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des candidatures fondateur en attente (#61) : triable (contact,
 * tenant, date), recherchable (email). Matérialisée en mémoire.
 */
#[AsListeSource('candidatures')]
final class SourceCandidatures implements SourceListe
{
    public function __construct(
        private readonly CandidaturesEnAttente $candidatures,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->candidatures->liste(),
            $this->colonnes(),
            [
                'contact' => static fn (CandidatureEnAttente $c): string => $c->email() ?? '',
                'tenant' => static fn (CandidatureEnAttente $c): int => $c->tenantId(),
                'date' => static fn (CandidatureEnAttente $c): int => $c->creeLe()->getTimestamp(),
            ],
            [static fn (CandidatureEnAttente $c): string => $c->email() ?? ''],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'contact', 'tenant');
    }
}
