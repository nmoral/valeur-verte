<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Ce qu'un hub départemental (#337) affiche du modèle publié pour un seul
 * département : ses chiffres ({@see MetriqueDepartement}), le millésime et ses
 * dates (source + millésime obligatoires, critère d'acceptation), et de quoi le
 * situer dans le national (médiane et nombre de départements couverts).
 */
final readonly class FicheDepartement
{
    public function __construct(
        public int $millesime,
        public \DateTimeImmutable $publieLe,
        public ?\DateTimeImmutable $calculeLe,
        public MetriqueDepartement $departement,
        public ?int $medianeNationaleEc,
        public int $departementsCouverts,
    ) {
    }
}
