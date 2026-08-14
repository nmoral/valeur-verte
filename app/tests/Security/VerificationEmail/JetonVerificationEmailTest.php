<?php

declare(strict_types=1);

namespace App\Tests\Security\VerificationEmail;

use App\Security\VerificationEmail\JetonVerificationEmail;
use PHPUnit\Framework\TestCase;

/**
 * Jeton de vérification d'email (#105, ONB-1). Signe l'identifiant de l'utilisateur
 * par HMAC-SHA256 avec le secret applicatif : le lien de confirmation placé dans
 * l'email est infalsifiable sans stocker de jeton. Vérification idempotente, donc
 * un jeton stateless suffit (pas d'usage unique ni d'expiration).
 */
final class JetonVerificationEmailTest extends TestCase
{
    public function testLeJetonEstStableEtValidePourSonUtilisateur(): void
    {
        $jetons = new JetonVerificationEmail('un-secret');

        $jeton = $jetons->pour(42);

        self::assertNotSame('', $jeton);
        self::assertSame($jeton, $jetons->pour(42));
        self::assertTrue($jetons->estValide(42, $jeton));
    }

    public function testUnJetonDUnAutreUtilisateurEstRejete(): void
    {
        $jetons = new JetonVerificationEmail('un-secret');

        self::assertFalse($jetons->estValide(43, $jetons->pour(42)));
    }

    public function testUnJetonFalsifieOuVideEstRejete(): void
    {
        $jetons = new JetonVerificationEmail('un-secret');
        $jeton = $jetons->pour(42);

        self::assertFalse($jetons->estValide(42, $jeton.'x'));
        self::assertFalse($jetons->estValide(42, ''));
    }

    public function testUnAutreSecretProduitUnAutreJeton(): void
    {
        self::assertNotSame(
            (new JetonVerificationEmail('secret-a'))->pour(1),
            (new JetonVerificationEmail('secret-b'))->pour(1),
        );
    }
}
