<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\Maille;
use PHPUnit\Framework\TestCase;

/**
 * Une maille publiée, telle que la page de publication la lit (#48, #224).
 *
 * Les coefficients DPE sont des **effets log** (référentiel moteur §4) : le gain
 * lisible par un lecteur est ``exp(coef_vers − coef_depuis) − 1``, en pourcentage.
 */
final class MailleTest extends TestCase
{
    public function testLeGainEstLExponentielleDeLEcartDesCoefficients(): void
    {
        $maille = new Maille('31001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]);

        // exp(0.11 − (−0.19)) − 1 = 34,99 %
        self::assertSame(35, $maille->gain('E', 'C'));
    }

    public function testLeGainVersLaClasseDSeCalculeSurLaMemeBase(): void
    {
        $maille = new Maille('31001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]);

        // exp(0 − (−0.19)) − 1 = 20,92 %
        self::assertSame(21, $maille->gain('E', 'D'));
    }

    public function testLeGainEstIndisponibleQuandUneClasseNEstPasPubliee(): void
    {
        $maille = new Maille('31001', 'local', ['D' => 0.0, 'C' => 0.11]);

        self::assertNull($maille->gain('E', 'C'));
    }

    public function testLeDepartementEstLePrefixeDuCodeInsee(): void
    {
        self::assertSame('31', (new Maille('31001', 'local', []))->departement());
    }

    public function testLeDepartementCorseGardeSaLettre(): void
    {
        self::assertSame('2A', (new Maille('2A004', 'local', []))->departement());
    }
}
