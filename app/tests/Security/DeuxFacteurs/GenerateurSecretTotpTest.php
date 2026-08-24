<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\DeuxFacteurs\Base32;
use App\Security\DeuxFacteurs\CodeTotp;
use App\Security\DeuxFacteurs\GenerateurSecretTotp;
use PHPUnit\Framework\TestCase;

/**
 * Secret partagé du second facteur (#312) : ce que le serveur garde et ce que
 * l'application d'authentification reçoit, via l'URI otpauth:// que le QR encode.
 */
final class GenerateurSecretTotpTest extends TestCase
{
    public function testLeSecretPorteAuMoinsCentSoixanteBitsDAlea(): void
    {
        $secret = (new GenerateurSecretTotp())->nouveau();

        // 160 bits, taille recommandée par la RFC 4226 (§4, R6) et celle qu'attendent
        // les applications d'authentification. En dessous, le secret devient
        // attaquable hors ligne à partir de quelques codes observés.
        self::assertGreaterThanOrEqual(20, \strlen(Base32::decoder($secret)));
    }

    public function testLeSecretEstDuBase32ExploitableTelQuel(): void
    {
        $secret = (new GenerateurSecretTotp())->nouveau();

        // Il doit traverser CodeTotp sans conversion : c'est la forme sous laquelle
        // il est stocké, affiché et transmis.
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        self::assertMatchesRegularExpression('/^\d{6}$/', (new CodeTotp($secret))->pour(new \DateTimeImmutable()));
    }

    public function testDeuxSecretsNeSeRessemblentPas(): void
    {
        $generateur = new GenerateurSecretTotp();

        self::assertNotSame($generateur->nouveau(), $generateur->nouveau());
    }

    public function testLUriOtpauthNommeLeProduitEtLeCompte(): void
    {
        $uri = (new GenerateurSecretTotp())->uriOtpauth('JBSWY3DPEHPK3PXP', 'admin@valeurverte.fr', 'Valeur Verte');

        // C'est cette chaîne que le QR encode. L'émetteur fait apparaître le produit
        // dans la liste de l'application ; sans lui, l'admin voit une ligne anonyme.
        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=Valeur%20Verte', $uri);
        self::assertStringContainsString('Valeur%20Verte:admin%40valeurverte.fr', $uri);
    }

    public function testLUriSenTientAuxParametresParDefaut(): void
    {
        $uri = (new GenerateurSecretTotp())->uriOtpauth('JBSWY3DPEHPK3PXP', 'admin@valeurverte.fr', 'Valeur Verte');

        // Google Authenticator ignore silencieusement algorithm/digits/period et
        // applique SHA1/6/30 quoi qu'il arrive : les écrire ne servirait qu'à
        // laisser croire qu'on peut en changer.
        self::assertStringNotContainsString('algorithm=', $uri);
        self::assertStringNotContainsString('digits=', $uri);
        self::assertStringNotContainsString('period=', $uri);
    }
}
