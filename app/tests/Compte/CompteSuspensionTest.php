<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Compte;
use PHPUnit\Framework\TestCase;

final class CompteSuspensionTest extends TestCase
{
    public function testUnCompteEstActifParDefaut(): void
    {
        $compte = Compte::creer(1, new \DateTimeImmutable('2026-01-01'));

        self::assertFalse($compte->estSuspendu());
        self::assertNull($compte->suspenduLe());
    }

    public function testSuspendrePuisReactiver(): void
    {
        $compte = Compte::creer(1, new \DateTimeImmutable('2026-01-01'));
        $quand = new \DateTimeImmutable('2026-02-01 10:00:00');

        $compte->suspendre($quand);
        self::assertTrue($compte->estSuspendu());
        self::assertEquals($quand, $compte->suspenduLe());

        $compte->reactiver();
        self::assertFalse($compte->estSuspendu());
        self::assertNull($compte->suspenduLe());
    }

    public function testSuspendreEstIdempotentSurLaPremiereDate(): void
    {
        $compte = Compte::creer(1, new \DateTimeImmutable('2026-01-01'));
        $premier = new \DateTimeImmutable('2026-02-01 10:00:00');

        $compte->suspendre($premier);
        $compte->suspendre(new \DateTimeImmutable('2026-03-01 10:00:00'));

        self::assertEquals($premier, $compte->suspenduLe());
    }

    public function testProlongerEssaiNeReculeJamais(): void
    {
        $compte = Compte::creer(1, new \DateTimeImmutable('2026-01-01'));

        $compte->prolongerEssaiJusqua(new \DateTimeImmutable('2026-03-01'));
        $compte->prolongerEssaiJusqua(new \DateTimeImmutable('2026-02-01'));

        self::assertEquals(new \DateTimeImmutable('2026-03-01'), $compte->essaiProlongeJusquau());
    }
}
