<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des snapshots de présence externe (#346) : les plus récents
 * d'abord, triables par date/clics/impressions/position. Archive en lecture seule,
 * faible volumétrie (un rapport hebdomadaire) → tri/pagination en mémoire.
 */
#[AsListeSource('rapports_presence')]
final class SourceRapportsPresence implements SourceListe
{
    private const int PLAFOND = 500;

    public function __construct(
        private readonly RapportPresenceRepository $rapports,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->rapports->recents(self::PLAFOND),
            $this->colonnes(),
            [
                'date' => static fn (RapportPresence $r): int => $r->executeLe()->getTimestamp(),
                'clics' => static fn (RapportPresence $r): int => $r->clics(),
                'impressions' => static fn (RapportPresence $r): int => $r->impressions(),
                'position' => static fn (RapportPresence $r): float => $r->positionMoyenne() ?? \PHP_FLOAT_MAX,
            ],
            [],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'clics', 'impressions', 'position');
    }
}
