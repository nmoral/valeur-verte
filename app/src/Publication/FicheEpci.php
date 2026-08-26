<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Ce qu'un hub EPCI (#363) affiche du modèle publié pour une intercommunalité :
 * ses chiffres agrégés ({@see MetriqueEpci}), le millésime et ses dates (source +
 * millésime obligatoires, comme le hub départemental), et l'**annuaire** de ses
 * communes membres mesurées (maillage descendant, liens gatés par l'indexabilité
 * #361).
 */
final readonly class FicheEpci
{
    /**
     * @param list<LienCommune>                                    $annuaire     communes membres mesurées, ordonnées par nom
     * @param list<array{code: string, nom: string, slug: string}> $departements département(s) que l'EPCI recouvre (maillage montant, JSON-LD) — un EPCI peut chevaucher deux départements
     */
    public function __construct(
        public int $millesime,
        public \DateTimeImmutable $publieLe,
        public ?\DateTimeImmutable $calculeLe,
        public MetriqueEpci $epci,
        public array $annuaire,
        public array $departements,
    ) {
    }
}
