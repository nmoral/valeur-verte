<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Publication\EpciMesures;
use App\Publication\PerimetrePublic;

/**
 * Publie au sitemap (#365) un hub EPCI par EPCI mesuré, tant que le type « EPCI » est
 * ouvert au périmètre publié (#393). En phase 1 il est fermé : aucun hub EPCI au
 * sitemap (ils répondent 404). {@see EpciMesures} ne renvoie rien sans millésime publié.
 */
final readonly class EpciSitemapProvider implements SitemapUrlProvider
{
    public function __construct(
        private EpciMesures $epci,
        private PerimetrePublic $perimetre,
    ) {
    }

    public function routes(): iterable
    {
        if (!$this->perimetre->epciPublics()) {
            return;
        }

        foreach ($this->epci->slugs() as $slug) {
            yield ['hub_epci', ['epci' => $slug]];
        }
    }
}
