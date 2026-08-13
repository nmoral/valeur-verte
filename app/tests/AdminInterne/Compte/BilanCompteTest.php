<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\BilanCompte;
use App\AdminInterne\Compte\StatutEssai;
use PHPUnit\Framework\TestCase;

final class BilanCompteTest extends TestCase
{
    public function testPorteLIdentiteLaDateEtDelegueLeCompteurAuStatut(): void
    {
        $creeLe = new \DateTimeImmutable('2026-08-01 09:00:00');
        $statut = StatutEssai::depuis(consommes: 3, seuil: 10);

        $bilan = new BilanCompte(
            tenantId: 7,
            identite: 'SARL Toiture Occitane',
            creeLe: $creeLe,
            statut: $statut,
        );

        self::assertSame(7, $bilan->tenantId());
        self::assertSame('SARL Toiture Occitane', $bilan->identite());
        self::assertEquals($creeLe, $bilan->creeLe());
        self::assertSame($statut, $bilan->statut());
        self::assertSame(3, $bilan->rapportsConsommes());
    }
}
