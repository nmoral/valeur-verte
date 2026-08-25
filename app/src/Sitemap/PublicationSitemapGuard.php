<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Publication\LectureModelePublie;

/**
 * La page de publication (#48/#224) répond 404 tant qu'aucun millésime n'a été
 * publié à la main (REG-2). Sa ligne n'entre au sitemap que lorsqu'elle mène
 * réellement à une page — jamais une URL vers un 404.
 */
final readonly class PublicationSitemapGuard implements SitemapRouteGuard
{
    public function __construct(private LectureModelePublie $modelePublie)
    {
    }

    public function routeName(): string
    {
        return 'publication';
    }

    public function estIndexable(): bool
    {
        return null !== $this->modelePublie->publication();
    }
}
