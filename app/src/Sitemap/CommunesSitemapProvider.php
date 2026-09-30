<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Publication\CommunesIndexables;
use App\Publication\PerimetrePublic;

/**
 * Publie au sitemap (#365) une page commune par commune **indexable** (#361), tant
 * que le type « communes » est ouvert au périmètre publié (#393). En phase 1 il est
 * fermé : aucune commune au sitemap (elles répondent 404). Le filtrage d'indexabilité
 * vit dans {@see CommunesIndexables} (une commune en repli reste hors sitemap).
 */
final readonly class CommunesSitemapProvider implements SitemapUrlProvider
{
    public function __construct(
        private CommunesIndexables $communes,
        private PerimetrePublic $perimetre,
    ) {
    }

    public function routes(): iterable
    {
        if (!$this->perimetre->communesPubliques()) {
            return;
        }

        foreach ($this->communes->slugs() as $slug) {
            yield ['commune_valeur_verte', ['departement' => $slug['departement'], 'commune' => $slug['commune']]];
        }
    }
}
