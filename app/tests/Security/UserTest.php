<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testLUtilisateurPorteLaFormeCanoniqueDeSonAdresse(): void
    {
        $user = new User('Pro+Devis@Gmail.com', ['ROLE_PRO']);

        // L'adresse de connexion garde l'alias : c'est là que le pro relève son courrier.
        self::assertSame('pro+devis@gmail.com', $user->getEmail());
        // L'identité, elle, est unique — c'est elle qui porte la contrainte d'unicité.
        self::assertSame('pro@gmail.com', $user->emailCanonique());
    }

    public function testChangerDAdresseRecalculeLaFormeCanonique(): void
    {
        $user = new User('pro@exemple.fr', ['ROLE_PRO']);

        $user->changerEmail('Nouveau+X@Googlemail.com');

        self::assertSame('nouveau+x@googlemail.com', $user->getEmail());
        self::assertSame('nouveau@gmail.com', $user->emailCanonique());
    }
}
