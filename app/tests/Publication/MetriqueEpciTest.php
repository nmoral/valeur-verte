<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\Maille;
use App\Publication\MetriqueEpci;
use PHPUnit\Framework\TestCase;

/**
 * Agrégat d'un EPCI (#363) : synthèse des mailles publiées de ses communes —
 * nombre de communes mesurées, gain médian E→C, supplément médian C→B, et part
 * des mailles estimées au-delà du local (cran EPCI ou départemental, cascade #169)
 * qui dilue la mesure. Calqué sur {@see \App\Publication\MetriqueDepartement}, mais
 * le regroupement est porté par le rattachement EPCI (pas le préfixe INSEE).
 */
final class MetriqueEpciTest extends TestCase
{
    private const COEF = ['D' => 0.0, 'C' => 0.11, 'B' => 0.19, 'E' => -0.19];

    public function testAgregeLesGainsMediansEtLeNombreDeCommunes(): void
    {
        $metrique = MetriqueEpci::pour('243300316', 'Bordeaux Métropole', [
            new Maille('33063', 'local', self::COEF),
            new Maille('33065', 'local', self::COEF),
        ]);

        self::assertSame('243300316', $metrique->codeEpci);
        self::assertSame('Bordeaux Métropole', $metrique->nom);
        self::assertSame(2, $metrique->zones);
        self::assertSame(35, $metrique->gainEc, 'E → C médian.');
        self::assertSame(8, $metrique->gainCb, 'C → B médian.');
        self::assertSame(0, $metrique->partNonLocale, 'Deux mailles locales → 0 % non-local.');
    }

    public function testLaPartNonLocaleCompteEpciEtDepartemental(): void
    {
        // Une locale, une intercommunale, une départementale → 2/3 non-locales.
        $metrique = MetriqueEpci::pour('243300316', 'Bordeaux Métropole', [
            new Maille('33063', 'local', self::COEF),
            new Maille('33065', 'epci', self::COEF),
            new Maille('33067', 'departemental', self::COEF),
        ]);

        self::assertSame(67, $metrique->partNonLocale);
    }

    public function testUnGainEstNulSiLaClasseNEstPasPubliee(): void
    {
        // Sans classe B publiée, le cran C→B n'est pas calculable → null.
        $metrique = MetriqueEpci::pour('243300316', 'Bordeaux Métropole', [
            new Maille('33063', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
        ]);

        self::assertSame(35, $metrique->gainEc);
        self::assertNull($metrique->gainCb);
    }
}
