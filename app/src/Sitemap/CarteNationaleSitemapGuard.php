<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Publication\LectureModelePublie;

/**
 * La page-carte nationale (#337) répond 404 tant qu'aucun millésime n'est publié,
 * comme la page d'étude dont elle reprend la carte (#48/#224). Sa ligne n'entre au
 * sitemap que lorsqu'elle mène à une page réelle.
 */
final readonly class CarteNationaleSitemapGuard implements SitemapRouteGuard
{
    public function __construct(private LectureModelePublie $modelePublie)
    {
    }

    public function routeName(): string
    {
        return 'carte_nationale';
    }

    public function estIndexable(): bool
    {
        return null !== $this->modelePublie->publication();
    }
}
