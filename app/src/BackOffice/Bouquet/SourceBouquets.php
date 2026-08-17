<?php

declare(strict_types=1);

namespace App\BackOffice\Bouquet;

use App\Liste\AsListeSource;
use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;

/**
 * Source de liste des bouquets types du pro (#35) : triable et recherchable par
 * nom, paginée. Matérialisée en mémoire (à défaut, les bouquets types par défaut).
 */
#[AsListeSource('bouquets')]
final class SourceBouquets implements SourceListe
{
    public function __construct(
        private readonly BouquetRepository $repository,
    ) {
    }

    public function interroger(ParametresListe $parametres): PageListe
    {
        $source = new SourceEnMemoire(
            $this->repository->disponibles(),
            $this->colonnes(),
            ['nom' => static fn (Bouquet $b): string => $b->nom()],
            [static fn (Bouquet $b): string => $b->nom()],
        );

        return $source->interroger($parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return ColonnesTriables::de('nom');
    }
}
