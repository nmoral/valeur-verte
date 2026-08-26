<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Une entrée de l'annuaire des communes d'un EPCI (#363), prête pour le maillage
 * descendant du hub EPCI. Porte le nom, les slugs qui reconstruisent l'URL
 * ``/valeur-verte/{departementSlug}/{communeSlug}`` et l'indexabilité (#361) :
 * seule une commune ``indexable`` reçoit un lien ``<a>`` (on ne transmet pas
 * d'autorité vers une page en repli), les autres restent en simple texte.
 */
final readonly class LienCommune
{
    public function __construct(
        public string $nom,
        public string $departementSlug,
        public string $communeSlug,
        public bool $indexable,
    ) {
    }
}
