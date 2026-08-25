<?php

declare(strict_types=1);

namespace App\Sitemap;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Décide de l'inclusion au sitemap d'une route par ailleurs marquée
 * `options: ['sitemap' => true]` (#340), quand sa présence dépend de l'état de
 * l'application. Le cas normal — une page toujours joignable — n'a pas de garde ;
 * seules les routes conditionnelles en implémentent un (ex. la publication, en
 * 404 tant qu'aucun millésime n'est publié, #224).
 */
#[AutoconfigureTag('app.sitemap_guard')]
interface SitemapRouteGuard
{
    /**
     * Nom de la route gardée.
     */
    public function routeName(): string;

    /**
     * La route mène-t-elle aujourd'hui à une page indexable (jamais un 404) ?
     */
    public function estIndexable(): bool;
}
