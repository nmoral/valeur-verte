<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Un EPCI (intercommunalité) du référentiel géographique (schéma « data »,
 * ``data.epci`` #366), tel que servi par {@see RepertoireEpci} au hub EPCI de la
 * Vague 2 SEO (#338, #363).
 *
 * Objet-valeur : porte l'identité (``code_epci`` = SIREN) et le nom lisible. Son
 * slug d'URL est dérivé du nom par le contrat de slugification {@see Slug} — jamais
 * figé en base, toujours recalculé, comme {@see Commune}.
 */
final readonly class Epci
{
    public function __construct(
        public string $codeEpci,
        public string $nom,
    ) {
    }

    public function slug(): string
    {
        return Slug::depuis($this->nom)->valeur();
    }
}
