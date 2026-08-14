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

    private function poserResultatEnSession(KernelBrowser $client): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
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
