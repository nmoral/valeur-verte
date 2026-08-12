<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Branding;

use App\BackOffice\Branding\Couleur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CouleurTest extends TestCase
{
    public function testUneCouleurExposeSonCodeHexadecimal(): void
    {
        $couleur = Couleur::depuisHex('#0E4A5C');

        self::assertSame('#0E4A5C', $couleur->hex());
    }

    public function testLeCodeEstNormaliseEnMajuscules(): void
    {
        $couleur = Couleur::depuisHex('#0e4a5c');

        self::assertSame('#0E4A5C', $couleur->hex());
    }

    #[DataProvider('codesInvalides')]
    public function testUnCodeInvalideEstRefuse(string $hex): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Couleur::depuisHex($hex);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function codesInvalides(): iterable
    {
        yield 'sans dièse' => ['0E4A5C'];
        yield 'préfixe avant le dièse' => ['x#0E4A5C'];
        yield 'trop court' => ['#0E4A5'];
        yield 'trop long' => ['#0E4A5C0'];
        yield 'caractère non hexadécimal' => ['#0E4A5Z'];
        yield 'vide' => [''];
    }
}
