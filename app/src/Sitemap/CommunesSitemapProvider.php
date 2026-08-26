<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Publication\CommunesIndexables;

/**
 * Publie au sitemap (#365) une page commune par commune **indexable** (#361). Le
 * filtrage vit dans {@see CommunesIndexables} : une commune en repli reste
 * crawlable mais hors sitemap, comme sa meta robots.
 */
final readonly class CommunesSitemapProvider implements SitemapUrlProvider
{
    public function __construct(private CommunesIndexables $communes)
    {
    }

    public function routes(): iterable
    {
        foreach ($this->communes->slugs() as $slug) {
            yield ['commune_valeur_verte', ['departement' => $slug['departement'], 'commune' => $slug['commune']]];
        }
    }
}
