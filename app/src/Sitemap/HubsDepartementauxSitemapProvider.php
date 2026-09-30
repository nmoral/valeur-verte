<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Publication\FichesDepartementales;
use App\Publication\PerimetrePublic;
use App\Publication\RepertoireDepartements;

/**
 * Publie au sitemap un hub départemental (#337) par département réellement mesuré
 * **et** dans le périmètre publié (#393). Restreint aux mêmes départements que le
 * contrôleur ouvre (200) — un hub hors périmètre 404, il n'a rien à faire au sitemap.
 */
final readonly class HubsDepartementauxSitemapProvider implements SitemapUrlProvider
{
    public function __construct(
        private FichesDepartementales $fiches,
        private RepertoireDepartements $repertoire,
        private PerimetrePublic $perimetre,
    ) {
    }

    public function routes(): iterable
    {
        $codes = array_filter($this->fiches->codesMesures(), $this->perimetre->contient(...));

        foreach ($this->repertoire->annuaire($codes) as $entree) {
            yield ['hub_departemental', ['departement' => $entree['slug']]];
        }
    }
}
