<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\CarteDepartement;
use App\Publication\CommuneTracee;
use App\Publication\Maille;
use App\Publication\ProjectionDepartement;
use PHPUnit\Framework\TestCase;

/**
 * Assemblage de la choroplèthe communale d'un département (#392) : les contours
 * projetés (@see \App\Publication\ProjectionDepartement) et le gain vert E→C de
 * chaque commune, dans la structure que le script de carte consomme telle quelle.
 *
 * Une commune dessinée sans coefficient publié (refusée, hors périmètre) est
 * **tracée quand même** sans gain : une carte trouée ferait croire à une panne.
 *
 * Les contours de test tiennent tous dans l'emprise de la première commune (lon
 * 0→4, lat 59→61, médiane 60° → cos 0,5) pour que la boîte et les chemins soient
 * calculables à la main.
 */
final class CarteDepartementTest extends TestCase
{
    public function testLaCarteDecritLaBoiteProjetee(): void
    {
        $donnees = self::carte()->donnees();

        self::assertSame(560, $donnees['w']);
        self::assertSame(560, $donnees['h']);
    }

    public function testChaqueCommunePorteSonContourSonNomEtSonGain(): void
    {
        $donnees = self::carte()->donnees();

        self::assertSame(
            [
                'insee' => '31001',
                'nom' => 'Toulouse',
                'gain' => 35,
                'path' => 'M8.0,552.0 L552.0,552.0 L552.0,8.0 L8.0,8.0Z',
            ],
            $donnees['feats'][0],
        );
    }

    public function testUneCommuneSansMailleEstDessineeSansGain(): void
    {
        $donnees = self::carte()->donnees();

        self::assertSame('31003', $donnees['feats'][2]['insee']);
        self::assertNull($donnees['feats'][2]['gain'], 'Une commune sans coefficient est tracée sans chiffre.');
        self::assertNotSame('', $donnees['feats'][2]['path'], 'Mais elle est bien dessinée.');
    }

    public function testLeDomaineCouvreLesGainsMesures(): void
    {
        // Domaine de couleur = [min, max] des gains E→C mesurés (31002 = +11 %,
        // 31001 = +35 %) ; la commune sans gain n'y entre pas.
        $donnees = self::carte()->donnees();

        self::assertSame([11, 35], $donnees['dom']);
    }

    public function testSansAucunGainMesureLeDomaineEstNeutre(): void
    {
        $carte = new CarteDepartement([
            new CommuneTracee('09001', 'Foix', [[[0.0, 59.0], [1.0, 59.0], [1.0, 60.0]]], null),
        ]);

        self::assertSame([0, 0], $carte->donnees()['dom']);
    }

    public function testLaProjectionFournieEstUtilisee(): void
    {
        // Une projection sur mesure (boîte 100×100, sans marge) : la carte l'emploie
        // au lieu d'en fabriquer une par défaut.
        $carte = new CarteDepartement(
            [new CommuneTracee(
                '31001',
                'Toulouse',
                [[[0.0, 59.0], [4.0, 59.0], [4.0, 61.0], [0.0, 61.0]]],
                null,
            )],
            new ProjectionDepartement(largeurMax: 100, hauteurMax: 100, marge: 0.0),
        );

        self::assertSame(100, $carte->donnees()['w']);
        self::assertSame(100, $carte->donnees()['h']);
    }

    private static function carte(): CarteDepartement
    {
        return new CarteDepartement([
            new CommuneTracee(
                '31001',
                'Toulouse',
                [[[0.0, 59.0], [4.0, 59.0], [4.0, 61.0], [0.0, 61.0]]],
                new Maille('31001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
            ),
            new CommuneTracee(
                '31002',
                'Muret',
                [[[0.0, 60.0], [2.0, 60.0], [2.0, 61.0], [0.0, 61.0]]],
                new Maille('31002', 'local', ['D' => 0.0, 'C' => 0.05, 'E' => -0.05]),
            ),
            new CommuneTracee(
                '31003',
                'Blagnac',
                [[[0.0, 59.0], [1.0, 59.0], [1.0, 60.0], [0.0, 60.0]]],
                null,
            ),
        ]);
    }
}
