<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Branding;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use PHPUnit\Framework\TestCase;

final class BrandingTest extends TestCase
{
    public function testLeBrandingParDefautUtiliseLaCouleurDeMarqueEtRienDAutre(): void
    {
        // Un PDF est téléchargeable même sans logo ni configuration (ONB-4).
        $branding = Branding::parDefaut();

        self::assertEquals(Couleur::depuisHex('#0E4A5C'), $branding->couleurPrincipale());
        self::assertNull($branding->numeroRge());
        self::assertNull($branding->telephone());
        self::assertNull($branding->mentionsLegales());
        self::assertNull($branding->logoUrl());
    }

    public function testUneUrlDeLogoDangereuseEstRejeteeALaCreation(): void
    {
        // Frontière : une URL de logo vers une adresse interne (SSRF) est refusée
        // dès la création du branding, jamais stockée.
        $this->expectException(\InvalidArgumentException::class);

        Branding::creer(Couleur::depuisHex('#0E4A5C'), null, null, null, 'https://169.254.169.254/latest/meta-data/');
    }

    public function testUnBrandingExposeSesElements(): void
    {
        $branding = Branding::creer(
            Couleur::depuisHex('#B8480F'),
            'QB/54321',
            '05 61 00 00 00',
            'SARL Rénov — RCS Toulouse 123 456 789',
            '/uploads/logo.png',
        );

        self::assertSame('#B8480F', $branding->couleurPrincipale()->hex());
        self::assertSame('QB/54321', $branding->numeroRge());
        self::assertSame('05 61 00 00 00', $branding->telephone());
        self::assertSame('SARL Rénov — RCS Toulouse 123 456 789', $branding->mentionsLegales());
        self::assertSame('/uploads/logo.png', $branding->logoUrl());
    }

    public function testUnBrandingPorteLeNomDeLEntreprise(): void
    {
        // #110 : le rapport est à l'en-tête du pro — son nom d'entreprise remplace
        // le nom de l'outil. Optionnel (null par défaut), débarrassé des espaces.
        $branding = Branding::creer(
            Couleur::depuisHex('#0E4A5C'),
            null,
            null,
            null,
            null,
            nom: '  Rénov Toulouse  ',
        );

        self::assertSame('Rénov Toulouse', $branding->nom());
        self::assertNull(Branding::parDefaut()->nom());
    }

    public function testLesChampsTexteVidesSontRamenesANull(): void
    {
        $branding = Branding::creer(Couleur::depuisHex('#0E4A5C'), '  ', '', '   ', null);

        self::assertNull($branding->numeroRge());
        self::assertNull($branding->telephone());
        self::assertNull($branding->mentionsLegales());
    }

    public function testLeNumeroRgeEstDebarrasseDesEspacesSuperflus(): void
    {
        $branding = Branding::creer(Couleur::depuisHex('#0E4A5C'), '  QB/54321  ', null, null, null);

        self::assertSame('QB/54321', $branding->numeroRge());
    }
}
