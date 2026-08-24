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
}
