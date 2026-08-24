<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AdresseEmail;
use App\Security\AdresseEmailInvalide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdresseEmailTest extends TestCase
{
    public function testLAdresseEstRamenneeEnMinuscules(): void
    {
        $adresse = AdresseEmail::depuis('  Jean.Dupont@Exemple.FR  ');

        self::assertSame('jean.dupont@exemple.fr', $adresse->valeur());
        self::assertSame('jean.dupont@exemple.fr', (string) $adresse);
    }

    public function testLaFormeCanoniqueIgnoreLAliasApresLePlus(): void
    {
        $adresse = AdresseEmail::depuis('Pro+Devis@Exemple.fr');

        self::assertSame('pro@exemple.fr', $adresse->canonique());
        // L'alias reste l'adresse de connexion : c'est bien là que le pro relève son courrier.
        self::assertSame('pro+devis@exemple.fr', $adresse->valeur());
    }

    public function testUnePartieLocaleEntierementAliasResteIntacte(): void
    {
        // « +tag@… » est une adresse valide dont la partie locale EST « +tag ».
        // La vider ferait collisionner toutes les adresses du domaine sur « @… ».
        self::assertSame('+tag@exemple.fr', AdresseEmail::depuis('+Tag@Exemple.fr')->canonique());
    }

    public function testGooglemailEstUnAliasDeGmail(): void
    {
        $adresse = AdresseEmail::depuis('pro@googlemail.com');

        self::assertSame('pro@gmail.com', $adresse->canonique());
        // Le domaine de connexion, lui, n'est pas réécrit : c'est celui que le pro a saisi.
        self::assertSame('pro@googlemail.com', $adresse->valeur());
    }

    #[DataProvider('saisiesInvalides')]
    public function testUneSaisieQuiNEstPasUneAdresseEstRefusee(string $saisie): void
    {
        $this->expectException(AdresseEmailInvalide::class);

        AdresseEmail::depuis($saisie);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function saisiesInvalides(): iterable
    {
        yield 'vide' => [''];
        yield 'sans arobase' => ['pro.exemple.fr'];
        yield 'sans domaine' => ['pro@'];
        yield 'sans partie locale' => ['@exemple.fr'];
        yield 'espace au milieu' => ['pro pro@exemple.fr'];
    }

    public function testUneSaisieInvalideNeLeveRienAvecEstValide(): void
    {
        self::assertTrue(AdresseEmail::estValide('  Pro@Exemple.fr '));
        self::assertFalse(AdresseEmail::estValide('pro.exemple.fr'));
    }
}
