<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * L'étude publiée : ce que la page publique affiche du modèle — son millésime et
 * ses dates (#48), sa couverture, ses médianes nationales et sa carte (#224).
 */
final readonly class Etude
{
    /**
     * @param array{w: int, h: int, feats: list<array{dept: string, nom: string, path: string, m: array{n: int, gain: ?int, gainED: ?int, dept: int}|null}>} $carte
     */
    public function __construct(
        public int $millesime,
        public \DateTimeImmutable $publieLe,
        public ?\DateTimeImmutable $calculeLe,
        public int $zonesMesurees,
        public int $departementsCouverts,
        public int $communesReferencees,
        public ?int $medianeGainEc,
        public ?int $medianeGainEd,
        public array $carte,
    ) {
    }
}
