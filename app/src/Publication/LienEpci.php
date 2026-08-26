<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Une entrée de l'annuaire des EPCI d'un département (#364), prête pour le maillage
 * descendant du hub départemental. Porte le nom et le slug qui reconstruit l'URL
 * ``/valeur-verte/epci/{slug}``. Pas d'indexabilité : un hub EPCI est toujours
 * indexable (le gating #361 ne concerne que les communes), donc toujours lié.
 */
final readonly class LienEpci
{
    public function __construct(
        public string $nom,
        public string $slug,
    ) {
    }
}
