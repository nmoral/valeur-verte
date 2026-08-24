<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\DoublonsIdentite;
use PHPUnit\Framework\TestCase;

final class DoublonsIdentiteTest extends TestCase
{
    public function testDesAdressesToutesDistinctesNeDonnentAucunGroupe(): void
    {
        $groupes = DoublonsIdentite::detecter([
            1 => 'un@exemple.fr',
            2 => 'deux@exemple.fr',
        ]);

        self::assertSame([], $groupes);
    }

    public function testLesEcrituresDUneMemeIdentiteSontRegroupees(): void
    {
        $groupes = DoublonsIdentite::detecter([
            1 => 'p.r.o@gmail.com',
            2 => 'P.R.O+Test@googlemail.com',
            3 => 'seul@exemple.fr',
            4 => 'pro@gmail.com',
        ]);

        // Le groupe est indexé par l'identité, et porte les comptes dans l'ordre
        // où ils viennent : le premier est le plus ancien, celui qu'on garde.
        self::assertSame(['pro@gmail.com' => [
            1 => 'p.r.o@gmail.com',
            2 => 'p.r.o+test@googlemail.com',
            4 => 'pro@gmail.com',
        ]], $groupes);
    }

    public function testUneAdresseIllisibleEstSignaleeAPartPlutotQueDIgnoree(): void
    {
        // Une ligne que la règle ne sait pas lire ne doit pas disparaître du
        // rapport : elle bloquera la migration sans qu'on sache pourquoi.
        $groupes = DoublonsIdentite::detecter([1 => 'pas-une-adresse', 2 => 'pas-une-adresse']);

        self::assertSame(['pas-une-adresse' => [1 => 'pas-une-adresse', 2 => 'pas-une-adresse']], $groupes);
    }
}
