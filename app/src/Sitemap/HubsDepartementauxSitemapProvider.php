<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Publication\FichesDepartementales;
use App\Publication\RepertoireDepartements;

/**
 * Publie au sitemap un hub départemental (#337) par département réellement mesuré
 * au millésime courant. Aucun garde séparé n'est nécessaire : la liste des codes
 * mesurés est déjà vide tant qu'aucun millésime n'est publié, et ne contient que
 * des départements qui répondent 200.
 */
final readonly class HubsDepartementauxSitemapProvider implements SitemapUrlProvider
{
    public function __construct(
        private FichesDepartementales $fiches,
        private RepertoireDepartements $repertoire,
    ) {
    }

    public function routes(): iterable
    {
        foreach ($this->repertoire->annuaire($this->fiches->codesMesures()) as $entree) {
            yield ['hub_departemental', ['departement' => $entree['slug']]];
        }
    }
}
