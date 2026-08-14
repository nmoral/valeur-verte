<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\GrilleCout;

use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\GrilleCoutEnregistree;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use PHPUnit\Framework\TestCase;

/**
 * Adaptateur de persistance de la grille de coûts (#34) : l'entité fige montants
 * et période, et restitue la grille à l'identique, sans que le domaine dépende de
 * Doctrine.
 */
final class GrilleCoutEnregistreeTest extends TestCase
{
    public function testElleRestitueLaGrilleDOrigine(): void
    {
        $grille = GrilleCout::parDefautNationale(PeriodeValidite::depuis(new \DateTimeImmutable('2026-03-01')));

        $relu = GrilleCoutEnregistree::depuis($grille)->versGrille();

        foreach (Poste::cases() as $poste) {
            self::assertSame($grille->montant($poste)->euros(), $relu->montant($poste)->euros());
        }
        self::assertEquals($grille->periode()->valideDu(), $relu->periode()->valideDu());
        self::assertNull($relu->periode()->valideAu());
    }
}
