<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des rapports d'audit SEO (#345) : les plus récents d'abord,
 * triables par date/score/URL/erreurs. Archive en lecture seule, aucune action de
 * groupe. Faible volumétrie (un rapport hebdomadaire) → tri/pagination en mémoire.
 */
#[AsListeSource('rapports_seo')]
final class SourceRapportsSeo implements SourceListe
{
    private const PLAFOND = 500;

    public function __construct(
        private readonly RapportSeoRepository $rapports,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->rapports->recents(self::PLAFOND),
            $this->colonnes(),
            [
                'date' => static fn (RapportSeo $r): int => $r->executeLe()->getTimestamp(),
                'score' => static fn (RapportSeo $r): int => $r->scoreTechnique(),
                'urls' => static fn (RapportSeo $r): int => $r->nombreUrls(),
                'erreurs' => static fn (RapportSeo $r): int => $r->nombreErreur(),
            ],
            [],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'score', 'urls', 'erreurs');
    }
}
