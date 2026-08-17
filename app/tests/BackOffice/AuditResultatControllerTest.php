<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\Restitution;
use App\Controller\BackOffice\AuditController;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

/**
 * Page de restitution dédiée (§5.3, #31) : elle ne s'atteint qu'après une saisie
 * (le résultat vit en session). Un accès direct renvoie vers l'écran de saisie.
 */
final class AuditResultatControllerTest extends WebTestCase
{
    public function testSansResultatEnSessionRenvoieVersLaSaisie(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://valeurverte.localhost/audit/resultat');

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertTrue(str_ends_with($location, '/audit'), 'Doit rediriger vers la saisie, pas : '.$location);
    }

    public function testLaRestitutionPorteLeCanalDeRetour(): void
    {
        $client = static::createClient();
        $this->poserResultatEnSession($client);

        $client->request('GET', 'http://valeurverte.localhost/audit/resultat');

        self::assertResponseIsSuccessful();
        // Bouton « ce chiffre me semble faux » (#49) posté vers la route de signalement.
        self::assertSelectorExists('form[data-async][action="/audit/resultat/signalement"]');
        self::assertSelectorTextContains('form[action="/audit/resultat/signalement"]', 'faux');
    }

    public function testLaRestitutionInviteAPersonnaliserApresLeRapport(): void
    {
        $client = static::createClient();
        $this->poserResultatEnSession($client);

        $client->request('GET', 'http://valeurverte.localhost/audit/resultat');

        self::assertResponseIsSuccessful();
        // ONB-4 : invitation à personnaliser (marque, coûts), jamais un mur — on
        // peut reporter. Le PDF reste téléchargeable sans configuration.
        self::assertSelectorExists('.personnaliser a[href="/marque"]');
        self::assertSelectorExists('.personnaliser a[href="/couts"]');
        self::assertSelectorTextContains('.personnaliser', 'Plus tard');
        self::assertSelectorExists('a[href="/audit/resultat/pdf"]');
    }

    public function testLesIndicateursPortentDesInfobullesExplicatives(): void
    {
        // ONB-8 (#110) : « tout ce qui aurait été expliqué de vive voix doit exister
        // dans le produit ». n, rayon et la fourchette (forme visible de l'erreur
        // médiane) portent une infobulle qui en explique le sens, sans jargon.
        $client = static::createClient();
        $this->poserResultatEnSession($client);

        $client->request('GET', 'http://valeurverte.localhost/audit/resultat');

        self::assertResponseIsSuccessful();
        // Chaque indicateur du bloc stats porte un marqueur d'infobulle.
        self::assertSelectorExists('.stat .infobulle');
        // Le sens de chaque valeur est explicité en clair.
        self::assertSelectorTextContains('body', 'ventes comparables');
        self::assertSelectorTextContains('body', 'autour du bien');
        self::assertSelectorTextContains('body', 'année de référence des ventes');
        // La fourchette explique l'incertitude (l'erreur médiane, jamais nommée).
        self::assertSelectorTextContains('body', "marge d'incertitude");
    }

    public function testLePrixDeBaseExpliqueLeMotMedianeSansJargon(): void
    {
        // ONB-8 (#110) : chasse au jargon. « médiane de la commune » est le seul terme
        // technique exposé au pro ; une infobulle l'explique en clair.
        $client = static::createClient();
        $this->poserResultatEnSession($client);

        $client->request('GET', 'http://valeurverte.localhost/audit/resultat');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.resultat-prix .infobulle');
        self::assertSelectorTextContains('body', 'moitié des ventes');
    }

    private function poserResultatEnSession(KernelBrowser $client): void
    {
        $factory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        $saisie = SaisieAudit::depuis('12 rue des Lilas, 31000 Toulouse', 90, 300, Dpe::E, Dpe::C);
        $restitution = Restitution::depuis(15000, 12000, 18000, 134, 10, 2024, 200000, false);
        $session->set(AuditController::SESSION_RESULTAT, [
            'saisie' => $saisie,
            'restitution' => $restitution,
            'reference' => '2026-0001',
        ]);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }
}
