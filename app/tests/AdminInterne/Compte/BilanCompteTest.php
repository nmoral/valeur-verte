<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\BilanCompte;
use App\Compte\EtatEssai;
use App\Compte\Formule;
use App\Compte\ParametresFormule;
use PHPUnit\Framework\TestCase;

final class BilanCompteTest extends TestCase
{
    public function testIlPorteIdentiteFormuleEtEtatDEssai(): void
    {
        $creeLe = new \DateTimeImmutable('2026-08-01 09:00:00');
        $essai = EtatEssai::calculer(3, new ParametresFormule(60, 10, null), $creeLe, new \DateTimeImmutable('2026-08-10 09:00:00'));

        $bilan = new BilanCompte(
            tenantId: 7,
            identite: 'SARL Toiture Occitane',
            creeLe: $creeLe,
            formule: Formule::Classique,
            essai: $essai,
        );

        self::assertSame(7, $bilan->tenantId());
        self::assertSame('SARL Toiture Occitane', $bilan->identite());
        self::assertEquals($creeLe, $bilan->creeLe());
        self::assertSame(Formule::Classique, $bilan->formule());
        self::assertSame(3, $bilan->essai()->rapportsConsommes());
    }
}
