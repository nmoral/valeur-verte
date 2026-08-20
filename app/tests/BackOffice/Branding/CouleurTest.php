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
        // #267 : la couleur est injectée dans un attribut `style` de la page
        // publique. Une valeur qui s'échappe de son contexte CSS ouvrirait une
        // requête sortante ou un habillage pirate — la borne est le format, strict.
        yield 'échappement de contexte CSS' => ['red;background:url(https://exfiltration.test/x)'];
        yield 'fermeture de déclaration' => ['#0E4A5C;}'];
        yield 'expression CSS' => ['expression(alert(1))'];
        yield 'url javascript' => ['javascript:alert(1)'];
    }
}
