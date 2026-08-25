<?php

declare(strict_types=1);

namespace App\Sitemap;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Fournit au sitemap des URLs que la **découverte des routes** ne peut pas trouver
 * seule (#337) : une famille de pages servie par une route à paramètre — un hub par
 * département, par exemple. {@see SitemapGenerator} ignore les routes paramétrées
 * (une URL à jeton n'est pas indexable) ; un provider énumère explicitement les
 * valeurs à publier, et lui seul sait lesquelles mènent à une page réelle.
 */
#[AutoconfigureTag('app.sitemap_url_provider')]
interface SitemapUrlProvider
{
    /**
     * Couples ``[nomRoute, paramètres]`` à générer, chacun menant à une page
     * indexable (jamais un 404).
     *
     * @return iterable<array{0: string, 1: array<string, string>}>
     */
    public function routes(): iterable;
}
