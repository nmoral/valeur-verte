<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf;

use App\BackOffice\Pdf\ReferenceRapportAleatoire;
use PHPUnit\Framework\TestCase;

/**
 * Générateur d'identifiants de rapport (§9.6) : date du jour + suffixe aléatoire.
 * Le suffixe garantit l'unicité entre deux rapports produits le même jour.
 */
final class ReferenceRapportAleatoireTest extends TestCase
{
    public function testGenereUneReferenceAuFormatAttendu(): void
    {
        $reference = (new ReferenceRapportAleatoire())->generer();

        self::assertMatchesRegularExpression('/^VV-\d{8}-[0-9A-Z]{6}$/', $reference->valeur());
    }

    public function testDeuxGenerationsProduisentDesIdentifiantsDifferents(): void
    {
        $generateur = new ReferenceRapportAleatoire();

        self::assertNotSame($generateur->generer()->valeur(), $generateur->generer()->valeur());
    }
}
