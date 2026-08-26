<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Publication\EpciMesures;

/**
 * Publie au sitemap (#365) un hub EPCI par EPCI mesuré. Aucun gating : les hubs
 * EPCI sont toujours indexables (#361 ne concerne que les communes) et
 * {@see EpciMesures} ne renvoie rien tant qu'aucun millésime n'est publié.
 */
final readonly class EpciSitemapProvider implements SitemapUrlProvider
{
    public function __construct(private EpciMesures $epci)
    {
    }

    public function routes(): iterable
    {
        foreach ($this->epci->slugs() as $slug) {
            yield ['hub_epci', ['epci' => $slug]];
        }
    }
}
