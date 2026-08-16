<?php

declare(strict_types=1);

namespace App\Tests\Publication\Double;

use App\Publication\LectureModelePublie;
use App\Publication\Maille;
use App\Publication\Publication;

/**
 * Modèle publié en mémoire : exerce l'assemblage de l'étude sans lire le schéma
 * « data ».
 */
final readonly class LectureModelePublieEnMemoire implements LectureModelePublie
{
    /**
     * @param list<Maille> $mailles
     */
    public function __construct(
        private ?Publication $publication,
        private array $mailles,
        private int $communesReferencees,
    ) {
    }

    public function publication(): ?Publication
    {
        return $this->publication;
    }

    public function mailles(): array
    {
        return $this->mailles;
    }

    public function communesReferencees(): int
    {
        return $this->communesReferencees;
    }
}
