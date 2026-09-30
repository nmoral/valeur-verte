<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\ProjectionDepartement;
use PHPUnit\Framework\TestCase;

/**
 * Projection des contours communaux (#392) : des anneaux lon/lat (WGS84) vers des
 * chemins SVG dans une boîte ajustée au département.
 *
 * La longitude est comprimée par ``cos(latitude)`` — sinon la France paraît étirée
 * d'est en ouest — et l'axe des y est inversé (le nord en haut), exactement comme
 * la carte nationale figée (``docs/carte-validation/generer.py``).
 *
 * Les cas de test choisissent une latitude médiane de 60° (``cos 60° = 0,5`` pile)
 * pour que la projection soit calculable à la main, donc vérifiable au chemin près.
 */
final class ProjectionDepartementTest extends TestCase
{
    public function testUnContourEstProjeteDansUneBoiteAjustee(): void
    {
        // Rectangle lon 0→4, lat 59→61 : médiane 60° (cos = 0,5), donc largeur
        // projetée 2 (= 4 × 0,5) égale à la hauteur 2 → boîte carrée.
        $projete = (new ProjectionDepartement())->projeter([
            ['cle' => '31001', 'anneaux' => [[[0.0, 59.0], [4.0, 59.0], [4.0, 61.0], [0.0, 61.0]]]],
        ]);

        // Échelle limitée par la hauteur : (560 − 16) / 2 = 272 px/unité.
        self::assertSame(560, $projete['w']);
        self::assertSame(560, $projete['h']);
        // Nord (lat 61) en haut (y = 8, la marge) ; sud (lat 59) en bas (y = 552).
        self::assertSame(
            'M8.0,552.0 L552.0,552.0 L552.0,8.0 L8.0,8.0Z',
            $projete['chemins']['31001'],
        );
    }

    public function testToutesLesCommunesPartagentLaMemeEchelle(): void
    {
        // Une seconde commune, incluse dans la boîte de la première : l'échelle et
        // le cadre restent ceux de la bounding box globale, pas ceux de chacune.
        $projete = (new ProjectionDepartement())->projeter([
            ['cle' => '31001', 'anneaux' => [[[0.0, 59.0], [4.0, 59.0], [4.0, 61.0], [0.0, 61.0]]]],
            ['cle' => '31002', 'anneaux' => [[[0.0, 60.0], [2.0, 60.0], [2.0, 61.0], [0.0, 61.0]]]],
        ]);

        self::assertSame(560, $projete['w']);
        self::assertSame(560, $projete['h']);
        self::assertSame(
            'M8.0,280.0 L280.0,280.0 L280.0,8.0 L8.0,8.0Z',
            $projete['chemins']['31002'],
        );
    }

    public function testUnContourAPlusieursAnneauxLesEnchaine(): void
    {
        // Un MultiPolygon (île + enclave) : chaque anneau devient un sous-chemin
        // « M…Z », concaténés — le remplissage even-odd creuse les trous.
        $projete = (new ProjectionDepartement())->projeter([
            ['cle' => '31001', 'anneaux' => [
                [[0.0, 59.0], [4.0, 59.0], [4.0, 61.0], [0.0, 61.0]],
                [[0.0, 60.0], [2.0, 60.0], [2.0, 61.0], [0.0, 61.0]],
            ]],
        ]);

        self::assertSame(
            'M8.0,552.0 L552.0,552.0 L552.0,8.0 L8.0,8.0Z'
            .'M8.0,280.0 L280.0,280.0 L280.0,8.0 L8.0,8.0Z',
            $projete['chemins']['31001'],
        );
    }

    public function testUneFormeLargeEstLimiteeParLaLargeur(): void
    {
        // Rectangle très large (lon 0→40 → largeur projetée 20) et peu haut
        // (lat 59→61 → hauteur 2) : c'est la largeur qui borne l'échelle.
        $projete = (new ProjectionDepartement())->projeter([
            ['cle' => '09001', 'anneaux' => [[[0.0, 59.0], [40.0, 59.0], [40.0, 61.0], [0.0, 61.0]]]],
        ]);

        // Échelle = (760 − 16) / 20 = 37,2 px/unité → hauteur = 2 × 37,2 + 16 = 90,4 → 90.
        self::assertSame(760, $projete['w']);
        self::assertSame(90, $projete['h']);
    }

    public function testSansContourLaBoiteEstVide(): void
    {
        $projete = (new ProjectionDepartement())->projeter([]);

        self::assertSame(0, $projete['w']);
        self::assertSame(0, $projete['h']);
        self::assertSame([], $projete['chemins']);
    }
}
