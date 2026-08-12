<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\GrilleCout;

use App\BackOffice\GrilleCout\PeriodeValidite;
use PHPUnit\Framework\TestCase;

final class PeriodeValiditeTest extends TestCase
{
    public function testUnePeriodeOuverteEstActiveADateDeDebut(): void
    {
        $periode = PeriodeValidite::depuis(new \DateTimeImmutable('2026-08-12 09:00:00'));

        self::assertTrue($periode->estActiveLe(new \DateTimeImmutable('2026-08-12 09:00:00')));
        self::assertTrue($periode->estActiveLe(new \DateTimeImmutable('2027-01-01 00:00:00')));
    }

    public function testUnePeriodeNestPasActiveAvantSonDebut(): void
    {
        $periode = PeriodeValidite::depuis(new \DateTimeImmutable('2026-08-12 09:00:00'));

        self::assertFalse($periode->estActiveLe(new \DateTimeImmutable('2026-08-11 23:59:59')));
    }

    public function testFermerNeMutePasLaPeriodeDOrigine(): void
    {
        // Invariant produit : aucun UPDATE destructif, on versionne (valide_du / valide_au).
        $ouverte = PeriodeValidite::depuis(new \DateTimeImmutable('2026-08-12 09:00:00'));

        $fermee = $ouverte->fermerLe(new \DateTimeImmutable('2026-09-01 09:00:00'));

        self::assertNull($ouverte->valideAu());
        self::assertEquals(new \DateTimeImmutable('2026-09-01 09:00:00'), $fermee->valideAu());
        self::assertNotSame($ouverte, $fermee);
    }

    public function testUnePeriodeFermeeNestPlusActiveApresSaCloture(): void
    {
        $periode = PeriodeValidite::depuis(new \DateTimeImmutable('2026-08-12 09:00:00'))
            ->fermerLe(new \DateTimeImmutable('2026-09-01 09:00:00'));

        self::assertTrue($periode->estActiveLe(new \DateTimeImmutable('2026-08-20 09:00:00')));
        self::assertFalse($periode->estActiveLe(new \DateTimeImmutable('2026-09-01 09:00:00')));
        self::assertFalse($periode->estActiveLe(new \DateTimeImmutable('2026-09-02 09:00:00')));
    }

    public function testOnNePeutPasFermerUnePeriodeAvantSonDebut(): void
    {
        $periode = PeriodeValidite::depuis(new \DateTimeImmutable('2026-08-12 09:00:00'));

        $this->expectException(\InvalidArgumentException::class);

        $periode->fermerLe(new \DateTimeImmutable('2026-08-01 09:00:00'));
    }
}
