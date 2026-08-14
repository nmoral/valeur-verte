<?php

declare(strict_types=1);

namespace App\Tests\Security\VerificationEmail;

use App\Security\User;
use PHPUnit\Framework\TestCase;

/**
 * État de vérification d'email porté par le compte (#105, ONB-1). Non vérifié à
 * la création (l'inscription est ouverte, la vérification vient a posteriori) ;
 * devient vérifié une fois la confirmation cliquée.
 */
final class UserVerificationEmailTest extends TestCase
{
    public function testUnCompteNestPasVerifieALaCreation(): void
    {
        $user = new User('artisan@example.com', ['ROLE_PRO']);

        self::assertFalse($user->emailEstVerifie());
    }

    public function testMarquerEmailVerifieRendLeCompteVerifie(): void
    {
        $user = new User('artisan@example.com', ['ROLE_PRO']);

        $user->marquerEmailVerifie(new \DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertTrue($user->emailEstVerifie());
    }
}
