<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Projette des contours communaux (anneaux lon/lat WGS84) en chemins SVG, dans une
 * boîte ajustée à l'emprise du département (#392).
 *
 * Même projection que la carte nationale figée (``docs/carte-validation/generer.py``) :
 * équirectangulaire, longitude comprimée par ``cos(latitude médiane)`` pour ne pas
 * étirer la carte d'est en ouest, axe des y inversé (nord en haut). Contrairement à
 * la carte nationale, la boîte n'est pas figée à 760×720 : chaque département a sa
 * propre emprise, on ajuste donc largeur et hauteur à son rapport réel.
 */
final readonly class ProjectionDepartement
{
    public function __construct(
        private int $largeurMax = 760,
        private int $hauteurMax = 560,
        private float $marge = 8.0,
    ) {
    }

    /**
     * @param list<array{cle: string, anneaux: list<list<array{0: float, 1: float}>>}> $formes
     *
     * @return array{w: int, h: int, chemins: array<string, string>}
     */
    public function projeter(array $formes): array
    {
        $points = [];
        foreach ($formes as $forme) {
            foreach ($forme['anneaux'] as $anneau) {
                foreach ($anneau as $point) {
                    $points[] = $point;
                }
            }
        }

        if ([] === $points) {
            return ['w' => 0, 'h' => 0, 'chemins' => []];
        }

        $latMin = min(array_map(static fn (array $p): float => $p[1], $points));
        $latMax = max(array_map(static fn (array $p): float => $p[1], $points));
        $cos = cos(deg2rad(($latMin + $latMax) / 2));

        // Repère projeté : x comprimé par cos(lat), y inversé (nord en haut).
        $xs = array_map(static fn (array $p): float => $p[0] * $cos, $points);
        $ys = array_map(static fn (array $p): float => -$p[1], $points);
        $xMin = min($xs);
        $yMin = min($ys);
        $largeurProj = max($xs) - $xMin;
        $hauteurProj = max($ys) - $yMin;

        $echelle = min(
            ($this->largeurMax - 2 * $this->marge) / max($largeurProj, 1e-9),
            ($this->hauteurMax - 2 * $this->marge) / max($hauteurProj, 1e-9),
        );

        $chemins = [];
        foreach ($formes as $forme) {
            $chemin = '';
            foreach ($forme['anneaux'] as $anneau) {
                $chemin .= $this->anneau($anneau, $cos, $xMin, $yMin, $echelle);
            }
            $chemins[$forme['cle']] = $chemin;
        }

        return [
            'w' => (int) round($largeurProj * $echelle + 2 * $this->marge),
            'h' => (int) round($hauteurProj * $echelle + 2 * $this->marge),
            'chemins' => $chemins,
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $anneau
     */
    private function anneau(array $anneau, float $cos, float $xMin, float $yMin, float $echelle): string
    {
        $sommets = [];
        foreach ($anneau as $point) {
            $x = $this->marge + ($point[0] * $cos - $xMin) * $echelle;
            $y = $this->marge + (-$point[1] - $yMin) * $echelle;
            $sommets[] = \sprintf('%.1f,%.1f', $x, $y);
        }

        return 'M'.implode(' L', $sommets).'Z';
    }
}
