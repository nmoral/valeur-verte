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

    public function testLIsolationSeuleCouvreLEnveloppe(): void
    {
        $bouquet = Bouquet::isolationSeule();

        self::assertSame('Isolation seule', $bouquet->nom());
        self::assertEqualsCanonicalizing(
            [Poste::Murs, Poste::Combles, Poste::PlanchersBas, Poste::Menuiseries],
            $bouquet->postes(),
        );
    }

    public function testLeChauffageSeulCouvreLesEquipements(): void
    {
        $bouquet = Bouquet::chauffageSeul();

        self::assertSame('Chauffage seul', $bouquet->nom());
        self::assertEqualsCanonicalizing(
            [Poste::Chauffage, Poste::Ecs, Poste::Vmc],
            $bouquet->postes(),
        );
    }
}
