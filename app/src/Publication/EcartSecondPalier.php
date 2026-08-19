<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Les départements où le second palier de rénovation (D→C) apporte le plus, et le
 * seuil que leur écart (gain E→C − gain E→D) dépasse — de quoi étayer « le
 * deuxième palier vaut autant que le premier » sans figer de noms.
 */
final readonly class EcartSecondPalier
{
    /**
     * @param list<string> $noms
     */
    public function __construct(
        public array $noms,
        public int $seuil,
    ) {
    }

    public function nomsJoints(): string
    {
        return Enumeration::et($this->noms);
    }
}
