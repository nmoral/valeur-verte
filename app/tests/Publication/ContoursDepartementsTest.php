<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\ContoursDepartements;
use PHPUnit\Framework\TestCase;

/**
 * Les contours départementaux sont un **asset figé** (projection SVG des unions de
 * ``data.commune_contour``, boîte 760×720) : une géométrie qui ne change pas d'un
 * millésime à l'autre n'a pas à être recalculée en base à chaque affichage.
 *
 * Ces tests sont le garde-fou de l'asset versionné : s'il est tronqué ou régénéré
 * avec une autre projection, la carte ne se dégrade pas en silence.
 */
final class ContoursDepartementsTest extends TestCase
{
    public function testTousLesDepartementsMetropolitainsOntUnContour(): void
    {
        $chemins = self::contours()->chemins();

        self::assertCount(96, $chemins, 'Métropole entière, Corse comprise.');
        self::assertArrayHasKey('2A', $chemins);
        self::assertArrayHasKey('2B', $chemins);
        self::assertArrayHasKey('75', $chemins);
    }

    public function testChaqueContourEstUnCheminSvgFerme(): void
    {
        foreach (self::contours()->chemins() as $departement => $chemin) {
            self::assertStringStartsWith('M', $chemin, \sprintf('Contour du département %s.', $departement));
            self::assertStringEndsWith('Z', $chemin, \sprintf('Contour du département %s.', $departement));
        }
    }

    public function testLesContoursTiennentDansLaBoiteDuGabarit(): void
    {
        $chemins = self::contours()->chemins();
        preg_match_all('/-?\d+(?:\.\d+)?/', implode('', $chemins), $coordonnees);
        $valeurs = array_map('floatval', $coordonnees[0]);
        $plusPetite = array_reduce($valeurs, static fn (float $borne, float $valeur) => min($borne, $valeur), 760.0);
        $plusGrande = array_reduce($valeurs, static fn (float $borne, float $valeur) => max($borne, $valeur), 0.0);

        self::assertGreaterThanOrEqual(0.0, $plusPetite);
        self::assertLessThanOrEqual(760.0, $plusGrande, 'Projection alignée sur le viewBox 760×720.');
    }

    private static function contours(): ContoursDepartements
    {
        return new ContoursDepartements(
            \dirname(__DIR__, 2).'/resources/publication/contours-departements.json',
        );
    }
}
