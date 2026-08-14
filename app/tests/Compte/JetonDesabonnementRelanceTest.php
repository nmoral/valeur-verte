<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\JetonDesabonnementRelance;
use PHPUnit\Framework\TestCase;

/**
 * Jeton de désabonnement (#108) : signe le tenant avec le secret applicatif pour
 * qu'un lien de désabonnement cliqué depuis un email — donc sans session — soit
 * infalsifiable sans stocker de jeton en base.
 */
final class JetonDesabonnementRelanceTest extends TestCase
{
    public function testLeJetonEstStableEtValidePourSonTenant(): void
    {
        $jetons = new JetonDesabonnementRelance('un-secret');

        $jeton = $jetons->pour(42);

        self::assertNotSame('', $jeton);
        self::assertSame($jeton, $jetons->pour(42));
        self::assertTrue($jetons->estValide(42, $jeton));
    }

    public function testUnJetonDUnAutreTenantEstRejete(): void
    {
        $jetons = new JetonDesabonnementRelance('un-secret');

        self::assertFalse($jetons->estValide(43, $jetons->pour(42)));
    }

    public function testUnJetonFalsifieOuVideEstRejete(): void
    {
        $jetons = new JetonDesabonnementRelance('un-secret');
        $jeton = $jetons->pour(42);

        self::assertFalse($jetons->estValide(42, $jeton.'x'));
        self::assertFalse($jetons->estValide(42, ''));
    }

    public function testUnAutreSecretProduitUnAutreJeton(): void
    {
        self::assertNotSame(
            (new JetonDesabonnementRelance('secret-a'))->pour(1),
            (new JetonDesabonnementRelance('secret-b'))->pour(1),
        );
    }
}
