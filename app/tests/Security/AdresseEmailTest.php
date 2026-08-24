<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AdresseEmail;
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
}
