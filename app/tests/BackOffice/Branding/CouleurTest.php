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

    /**
     * La page publique pose du texte **sur** la couleur du pro (#290) : une marque
     * claire — jaune, cyan, blanc cassé — rendrait du blanc sur blanc illisible.
     * La couleur de texte se décide donc ici, au contraste, et pas au doigt mouillé
     * dans un gabarit.
     */
    #[DataProvider('couleursEtTexteAttendu')]
    public function testLaCouleurDeTexteLisibleSuitLeContraste(string $marque, string $texteAttendu): void
    {
        self::assertSame($texteAttendu, Couleur::depuisHex($marque)->texteLisible()->hex());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function couleursEtTexteAttendu(): iterable
    {
        yield 'marque par défaut, sombre' => ['#0E4A5C', '#FFFFFF'];
        yield 'vert profond' => ['#1B7F5A', '#FFFFFF'];
        yield 'noir' => ['#000000', '#FFFFFF'];
        yield 'jaune vif' => ['#FFD400', '#111820'];
        yield 'cyan clair' => ['#7FE7F0', '#111820'];
        yield 'blanc' => ['#FFFFFF', '#111820'];
        // Le rouge pur est le cas contre-intuitif : on le croit sombre, mais la
        // luminance relative le place juste au-dessus du point de bascule (0,213
        // contre 0,198) — l'encre y contraste mieux que le blanc, de peu.
        yield 'rouge pur' => ['#FF0000', '#111820'];
    }

    /**
     * L'autre sens du même problème (#290) : le chiffre héros de la page est écrit
     * **dans** la couleur du pro, sur une carte blanche. Un jaune vif y disparaît —
     * symétrique exact du blanc sur blanc, et tout aussi coûteux : c'est le seul
     * chiffre que le visiteur est venu chercher.
     */
    #[DataProvider('couleursEtEncreAttendue')]
    public function testUneCouleurTropClairePourEcrireSurBlancRetombeSurLEncre(string $marque, string $attendu): void
    {
        self::assertSame($attendu, Couleur::depuisHex($marque)->lisibleSurFondClair()->hex());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function couleursEtEncreAttendue(): iterable
    {
        yield 'marque par défaut' => ['#0E4A5C', '#0E4A5C'];
        yield 'vert profond' => ['#1B7F5A', '#1B7F5A'];
        yield 'brique' => ['#B8480F', '#B8480F'];
        yield 'jaune vif' => ['#FFD400', '#111820'];
        yield 'cyan clair' => ['#7FE7F0', '#111820'];
        yield 'blanc' => ['#FFFFFF', '#111820'];
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
