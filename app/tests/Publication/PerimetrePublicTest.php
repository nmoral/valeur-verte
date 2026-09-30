<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\PerimetrePublic;
use PHPUnit\Framework\TestCase;

/**
 * Périmètre de publication (#393) : quelles pages programmatiques sont exposées au
 * go-live. Piloté par configuration pour élargir par phases — phase 1 : carte +
 * hubs des départements d'Occitanie, pas les communes ni les EPCI.
 */
final class PerimetrePublicTest extends TestCase
{
    public function testUnDepartementDuPerimetreEstContenu(): void
    {
        $perimetre = new PerimetrePublic(['31', '34'], communesPubliques: false, epciPublics: false);

        self::assertTrue($perimetre->contient('31'));
        self::assertFalse($perimetre->contient('33')); // hors périmètre
    }

    public function testLesTypesCommunesEtEpciSontDesactivablesIndependamment(): void
    {
        $tout = new PerimetrePublic(['31'], communesPubliques: true, epciPublics: true);
        self::assertTrue($tout->communesPubliques());
        self::assertTrue($tout->epciPublics());

        $phase1 = new PerimetrePublic(['31'], communesPubliques: false, epciPublics: false);
        self::assertFalse($phase1->communesPubliques());
        self::assertFalse($phase1->epciPublics());
    }

    public function testUnPerimetreOuvertContientTousLesDepartements(): void
    {
        // Sentinelle « * » = périmètre ouvert (phase finale du go-live, et défaut
        // permissif en environnement de test).
        $ouvert = new PerimetrePublic(['*'], communesPubliques: true, epciPublics: true);

        self::assertTrue($ouvert->contient('33'));
        self::assertTrue($ouvert->contient('75'));
    }

    public function testExposeLesDepartementsDuPerimetre(): void
    {
        $perimetre = new PerimetrePublic(['09', '31'], communesPubliques: false, epciPublics: false);

        self::assertSame(['09', '31'], $perimetre->departements());
    }
}
