<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Une commune telle que la carte départementale (#392) la dessine : son contour
 * (anneaux lon/lat WGS84, un MultiPolygon aplati) et, si elle est publiée, sa
 * maille — d'où se lit le gain vert E→C. ``null`` quand la commune est dessinée
 * mais non mesurée (refusée, hors périmètre).
 */
final readonly class CommuneTracee
{
    /**
     * @param list<list<array{0: float, 1: float}>> $anneaux
     */
    public function __construct(
        public string $codeInsee,
        public string $nom,
        public array $anneaux,
        public ?Maille $maille,
    ) {
    }
}
