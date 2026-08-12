<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Bouquet;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\GrilleCout\Poste;
use PHPUnit\Framework\TestCase;

final class BouquetParDefautTest extends TestCase
{
    public function testLeBouquetStandardSAppelleRenovationDAmpleurStandard(): void
    {
        self::assertSame(
            "Rénovation d'ampleur standard",
            Bouquet::renovationAmpleurStandard()->nom(),
        );
    }

    public function testLeBouquetStandardCouvreTousLesPostes(): void
    {
        // ONB-3 : un rapport complet doit être produisible dès la création du compte,
        // sans configuration — le bouquet par défaut couvre donc l'ensemble des postes.
        $bouquet = Bouquet::renovationAmpleurStandard();

        self::assertEqualsCanonicalizing(Poste::cases(), $bouquet->postes());
    }
}
