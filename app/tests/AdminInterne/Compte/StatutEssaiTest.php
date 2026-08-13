<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\StatutEssai;
use PHPUnit\Framework\TestCase;

final class StatutEssaiTest extends TestCase
{
    public function testEnEssaiTantQueLeSeuilNEstPasAtteint(): void
    {
        $statut = StatutEssai::depuis(consommes: 3, seuil: 10);

        self::assertTrue($statut->enEssai());
        self::assertFalse($statut->seuilAtteint());
        self::assertSame(3, $statut->rapportsConsommes());
        self::assertSame(7, $statut->rapportsRestants());
        self::assertSame(10, $statut->seuil());
    }

    public function testSeuilAtteintQuandLesRapportsEgalentOuDepassentLeSeuil(): void
    {
        $statut = StatutEssai::depuis(consommes: 10, seuil: 10);

        self::assertFalse($statut->enEssai());
        self::assertTrue($statut->seuilAtteint());
        self::assertSame(0, $statut->rapportsRestants());
    }

    public function testUnSeuilNonStrictementPositifEstRejete(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StatutEssai::depuis(consommes: 0, seuil: 0);
    }
}
