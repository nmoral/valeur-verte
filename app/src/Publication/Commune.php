<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Une commune du référentiel géographique (schéma « data »), telle que servie par
 * {@see RepertoireCommunes} aux pages SEO de la Vague 2 (#338, #359).
 *
 * Objet-valeur : porte l'identité (``code_insee``), le nom lisible, le département
 * et le rattachement EPCI (SIREN, ``null`` si absent en base). Son slug d'URL est
 * dérivé du nom par le contrat de slugification {@see Slug} — jamais figé en base,
 * toujours recalculé, pour que la règle reste unique.
 */
final readonly class Commune
{
    public function __construct(
        public string $codeInsee,
        public string $nom,
        public string $codeDepartement,
        public ?string $codeEpci,
    ) {
    }

    public function slug(): string
    {
        return Slug::depuis($this->nom)->valeur();
    }
}
