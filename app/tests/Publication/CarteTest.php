<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\Carte;
use App\Publication\Maille;
use App\Publication\MetriquesDepartementales;
use PHPUnit\Framework\TestCase;

/**
 * Assemblage des contours figés et des métriques du millésime publié : la structure
 * embarquée dans la page, consommée telle quelle par le script de la carte (#224).
 */
final class CarteTest extends TestCase
{
    public function testLaCarteDecritLaBoiteDuGabarit(): void
    {
        $donnees = self::carte()->donnees();

        self::assertSame(760, $donnees['w']);
        self::assertSame(720, $donnees['h']);
    }

    public function testChaqueDepartementPorteSonContourEtSesMetriques(): void
    {
        $donnees = self::carte()->donnees();

        self::assertSame(
            [
                'dept' => '31',
                'nom' => 'Haute-Garonne',
                'path' => 'M0,0 L1,1Z',
                'm' => ['n' => 2, 'gain' => 35, 'gainED' => 21, 'dept' => 0],
            ],
            $donnees['feats'][0],
        );
    }

    public function testUnDepartementHorsPerimetreEstDessineSansMetriques(): void
    {
        $donnees = self::carte()->donnees();

        self::assertSame('57', $donnees['feats'][1]['dept']);
        self::assertNull($donnees['feats'][1]['m'], 'L\'Alsace-Moselle est dessinée, mais sans chiffres.');
    }

    private static function carte(): Carte
    {
        $mailles = [
            new Maille('31001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
            new Maille('31002', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
        ];

        return new Carte(
            ['31' => 'M0,0 L1,1Z', '57' => 'M2,2 L3,3Z'],
            MetriquesDepartementales::depuisMailles($mailles),
        );
    }
}
