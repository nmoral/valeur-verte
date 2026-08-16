<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * La décision de publication d'un millésime (REG-2, #130) : ce que le public voit.
 *
 * ``calculeLe`` peut manquer tant qu'aucune maille du millésime n'est servie —
 * la décision existe alors sans données à dater.
 */
final readonly class Publication
{
    public function __construct(
        public int $millesime,
        public \DateTimeImmutable $publieLe,
        public ?\DateTimeImmutable $calculeLe,
    ) {
    }
}
