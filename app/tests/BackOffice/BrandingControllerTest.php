<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Branding\BrandingRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BrandingControllerTest extends WebTestCase
{
    public function testLEcranAfficheLeFormulaireEtLApercuAvecLaMarqueCourante(): void
    {
        $client = static::createClient();
        $client->request('GET', '/marque');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre marque');
        self::assertSelectorTextContains('nav.side a.on', 'Ma marque');
        // Zone d'aperçu du PDF présente pour la prévisualisation en direct.
        self::assertSelectorExists('#apercu-pdf');
        // Couleur de marque par défaut pré-remplie.
        self::assertSelectorExists('input[name="couleur"][value="#0E4A5C"]');
    }

    public function testEnregistrerUneMarqueValideLaPersisteEtRedirige(): void
    {
        $client = static::createClient();
        $client->request('POST', '/marque', [
            'couleur' => '#B8480F',
            'numero_rge' => 'QB/54321',
            'telephone' => '05 61 00 00 00',
        ]);

        self::assertResponseRedirects('/marque');

        $branding = self::getContainer()->get(BrandingRepository::class);
        self::assertInstanceOf(BrandingRepository::class, $branding);
        $courant = $branding->courant();
        self::assertSame('#B8480F', $courant->couleurPrincipale()->hex());
        self::assertSame('QB/54321', $courant->numeroRge());
    }

    public function testUneCouleurInvalideEstRefuseeSansRienEnregistrer(): void
    {
        $client = static::createClient();
        $client->request('POST', '/marque', ['couleur' => 'pas-une-couleur']);

        self::assertResponseStatusCodeSame(422);

        $branding = self::getContainer()->get(BrandingRepository::class);
        self::assertInstanceOf(BrandingRepository::class, $branding);
        // Rien n'a été enregistré : la marque courante reste celle par défaut.
        self::assertSame('#0E4A5C', $branding->courant()->couleurPrincipale()->hex());
    }
}
