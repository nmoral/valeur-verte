<?php

declare(strict_types=1);

namespace App\Publication;

use App\PourquoiIci\Phrase;

/**
 * Ce qu'un hub départemental (#337) affiche du modèle publié pour un seul
 * département : ses chiffres ({@see MetriqueDepartement}), le millésime et ses
 * dates (source + millésime obligatoires, critère d'acceptation), et de quoi le
 * situer dans le national (médiane et nombre de départements couverts).
 *
 * Porte aussi les hypothèses du bloc « Pourquoi ici ? » (#341) — au niveau
 * département, uniquement des hypothèses contextuelles (climat, marché), sans
 * phrase mesurée faute d'un effectif de ventes agrégé.
 */
final readonly class FicheDepartement
{
    /**
     * @param list<Phrase> $phrases
     */
    public function __construct(
        public int $millesime,
        public \DateTimeImmutable $publieLe,
        public ?\DateTimeImmutable $calculeLe,
        public MetriqueDepartement $departement,
        public ?int $medianeNationaleEc,
        public int $departementsCouverts,
        public array $phrases = [],
    ) {
    }
}
