<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\User;
use PHPUnit\Framework\TestCase;

/**
 * L'utilisateur porte la preuve de son acceptation des conditions (#228) : la
 * version acceptée et l'instant, sans quoi on ne peut pas prouver *quoi* a été
 * accepté ni *quand*.
 */
final class AcceptationCguTest extends TestCase
{
    public function testUnCompteNeufNAAcceptéAucuneCondition(): void
    {
        $user = new User('pro@example.com', ['ROLE_PRO']);

        self::assertFalse($user->aAccepteLesConditions());
        self::assertNull($user->versionConditionsAcceptee());
        self::assertNull($user->conditionsAccepteesLe());
    }

    public function testAccepterEnregistreLaVersionEtLInstant(): void
    {
        $user = new User('pro@example.com', ['ROLE_PRO']);
        $le = new \DateTimeImmutable('2026-08-16 14:30:00');

        $user->accepterLesConditions('2026-08-16', $le);

        self::assertTrue($user->aAccepteLesConditions());
        self::assertSame('2026-08-16', $user->versionConditionsAcceptee());
        self::assertEquals($le, $user->conditionsAccepteesLe());
    }
}
