<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\SiretInvalide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiretTest extends TestCase
{
    public function testUnSiretDeQuatorzeChiffresEstAccepte(): void
    {
        $siret = Siret::depuis('12345678901234');

        self::assertSame('12345678901234', $siret->valeur());
        self::assertSame('12345678901234', (string) $siret);
    }

    public function testLesEspacesDeFormatageSontRetires(): void
    {
        $siret = Siret::depuis('  123 456 789 01234 ');

        self::assertSame('12345678901234', $siret->valeur());
    }

    public function testLeSirenEstLesNeufPremiersChiffres(): void
    {
        $siret = Siret::depuis('12345678901234');

        self::assertSame('123456789', $siret->siren());
    }

    #[DataProvider('valeursInvalides')]
    public function testUneValeurNonConformeEstRejetee(string $valeur): void
    {
        $this->expectException(SiretInvalide::class);

        Siret::depuis($valeur);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valeursInvalides(): iterable
    {
        yield 'trop court' => ['1234567890123'];
        yield 'trop long' => ['123456789012345'];
        yield 'contient des lettres' => ['1234567890123A'];
        yield 'vide' => [''];
    }

    public function testEstValideDistingueLesEntreesConformes(): void
    {
        self::assertTrue(Siret::estValide('123 456 789 01234'));
        self::assertFalse(Siret::estValide('1234'));
        self::assertFalse(Siret::estValide('pas-un-siret'));
    }
}
