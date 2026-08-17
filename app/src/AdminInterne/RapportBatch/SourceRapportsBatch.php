<?php

declare(strict_types=1);

namespace App\AdminInterne\RapportBatch;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des rapports de batch (#131) : le lecteur DBAL rapatrie les
 * rapports archivés (schéma data, lecture seule), on trie/cherche/pagine en
 * mémoire. Tri par défaut : les plus récents d'abord (voir le composant). Volume
 * borné (deux exécutions par an et par département) : un plafond de sécurité suffit.
 */
#[AsListeSource('rapports_batch')]
final readonly class SourceRapportsBatch implements SourceListe
{
    private const PLAFOND = 2000;

    public function __construct(
        private LectureRapportsBatch $lecture,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->lecture->recents(self::PLAFOND),
            $this->colonnes(),
            [
                'date' => static fn (RapportBatch $r): int => $r->debut()->getTimestamp(),
                'phase' => static fn (RapportBatch $r): string => $r->estCalcul() ? 'calcul' : 'ingestion',
                'departement' => static fn (RapportBatch $r): string => $r->departement(),
                'millesime' => static fn (RapportBatch $r): int => $r->millesime(),
            ],
            [
                static fn (RapportBatch $r): string => $r->departement(),
                static fn (RapportBatch $r): string => (string) $r->millesime(),
            ],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'phase', 'departement', 'millesime');
    }
}
