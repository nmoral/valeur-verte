<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Un palier de gain E→C partagé par un ou plusieurs départements (ex. Vienne et
 * Meuse à +35 %) : la valeur commune et les noms qui la portent.
 */
final readonly class GroupeGain
{
    /**
     * @param list<string> $noms
     */
    public function __construct(
        public int $valeur,
        public array $noms,
    ) {
    }

    public function nomsJoints(): string
    {
        return Enumeration::et($this->noms);
    }
}
