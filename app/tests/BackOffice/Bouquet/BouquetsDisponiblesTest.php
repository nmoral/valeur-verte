<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Bouquet;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Bouquet\BouquetsDisponibles;
use PHPUnit\Framework\TestCase;

/**
 * Bouquets proposés à la sélection en rendez-vous (#29, §9.2). Tant que la
 * configuration back-office (#5) n'est pas branchée, la liste par défaut reste
 * exploitable — même principe que le branding par défaut : un rapport se produit
 * sans configuration préalable.
 */
final class BouquetsDisponiblesTest extends TestCase
{
    public function testLaListeParDefautOffreLesTroisBouquetsTypes(): void
    {
        $noms = array_map(
            static fn (Bouquet $bouquet): string => $bouquet->nom(),
            (new BouquetsDisponibles())->disponibles(),
        );

        self::assertSame(
            ["Rénovation d'ampleur standard", 'Isolation seule', 'Chauffage seul'],
            $noms,
        );
    }

    public function testParNomResoutUnBouquetProposé(): void
    {
        $bouquet = (new BouquetsDisponibles())->parNom('Isolation seule');

        self::assertInstanceOf(Bouquet::class, $bouquet);
        self::assertSame('Isolation seule', $bouquet->nom());
    }

    public function testParNomRenvoieNullPourUnBouquetInconnu(): void
    {
        self::assertNull((new BouquetsDisponibles())->parNom('Piscine chauffée'));
    }
}
