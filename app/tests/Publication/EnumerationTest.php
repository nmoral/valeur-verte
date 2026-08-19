<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\Enumeration;
use PHPUnit\Framework\TestCase;

/**
 * Énumération française d'une liste de noms : virgules puis « et » avant le
 * dernier. Sert à citer des départements dans les constats de la page publique
 * sans les figer dans le gabarit.
 */
final class EnumerationTest extends TestCase
{
    public function testUnSeulNom(): void
    {
        self::assertSame('Indre', Enumeration::et(['Indre']));
    }

    public function testDeuxNomsSontJointsParEt(): void
    {
        self::assertSame('Vienne et Meuse', Enumeration::et(['Vienne', 'Meuse']));
    }

    public function testTroisNomsOuPlusPrennentDesVirgulesPuisEt(): void
    {
        self::assertSame(
            'Var, Val-de-Marne et Alpes-Maritimes',
            Enumeration::et(['Var', 'Val-de-Marne', 'Alpes-Maritimes']),
        );
    }

    public function testAucunNomDonneUneChaineVide(): void
    {
        self::assertSame('', Enumeration::et([]));
    }
}
