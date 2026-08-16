<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\BackOffice\Restitution\Restitution;
use App\Controller\BackOffice\AuditController;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

/**
 * Ligne d'ajustement du professionnel sur la restitution (#30, §4). Le pro pose
 * une ligne séparée (montant signé + motif obligatoire) : la mesure statistique
 * reste affichée intacte et l'estimation retenue s'en déduit. Interdit hors mode
 * audit — la route vit dans l'espace back-office, jamais dans le widget public.
 */
final class AuditResultatAjustementTest extends WebTestCase
{
    private const REFERENCE = 'VV-20260814-AJ9999';
    private const MESURE = 40700;

    public function testLaRestitutionOffreLeFormulaireDAjustement(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $client->request('GET', 'http://valeurverte.localhost/audit/resultat');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/audit/resultat"][method="post"]');
        self::assertSelectorExists('form[action="/audit/resultat"] [name="motif"]');
        self::assertSelectorExists('form[action="/audit/resultat"] [name="montant"]');
    }

    public function testUnAjustementValideEstEnregistreEtAfficheLaLigneSeparee(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $crawler = $client->request('GET', 'http://valeurverte.localhost/audit/resultat');
        $formulaire = $crawler->filter('form[action="/audit/resultat"]')->form([
            'montant' => '-5000',
            'motif' => 'État général du bien',
        ]);
        $client->submit($formulaire);

        // Post/Redirect/Get : on revient sur la restitution.
        self::assertResponseStatusCodeSame(302);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Les trois lignes : mesure intacte, ajustement attribué, estimation retenue.
        self::assertSelectorTextContains('body', 'Ajustement du professionnel');
        self::assertSelectorTextContains('body', 'État général du bien');
        self::assertSelectorTextContains('body', 'Estimation retenue');
        self::assertSelectorTextContains('body', '35 700');

        // Persisté sur le rapport, dans son champ distinct.
        $rapport = $this->rapports()->parReference(self::REFERENCE);
        self::assertInstanceOf(Rapport::class, $rapport);
        $ajustement = $rapport->ajustement();
        self::assertNotNull($ajustement);
        self::assertSame(-5000, $ajustement->montantEuros());
        self::assertSame('État général du bien', $ajustement->motif());
    }

    public function testUnMotifVideEstRefuseEtNeTouchePasLeRapport(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $crawler = $client->request('GET', 'http://valeurverte.localhost/audit/resultat');
        $formulaire = $crawler->filter('form[action="/audit/resultat"]')->form([
            'montant' => '-5000',
            'motif' => '   ',
        ]);
        $client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'motif');

        $rapport = $this->rapports()->parReference(self::REFERENCE);
        self::assertInstanceOf(Rapport::class, $rapport);
        self::assertNull($rapport->ajustement());
    }

    public function testUnAjustementNulEstRefuse(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $crawler = $client->request('GET', 'http://valeurverte.localhost/audit/resultat');
        $formulaire = $crawler->filter('form[action="/audit/resultat"]')->form([
            'montant' => '0',
            'motif' => 'Sans effet',
        ]);
        $client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        $rapport = $this->rapports()->parReference(self::REFERENCE);
        self::assertInstanceOf(Rapport::class, $rapport);
        self::assertNull($rapport->ajustement());
    }

    public function testSansResultatEnSessionLAjustementRenvoieVersLaSaisie(): void
    {
        $client = static::createClient();

        $client->request('POST', 'http://valeurverte.localhost/audit/resultat', [
            'montant' => '-5000',
            'motif' => 'État général du bien',
        ]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertTrue(str_ends_with($location, '/audit'), 'Doit rediriger vers la saisie, pas : '.$location);
    }

    private function poserContexte(KernelBrowser $client): void
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, 31000 Toulouse', 90, 300, Dpe::E, Dpe::C, 4);
        $restitution = Restitution::depuis(self::MESURE, 30000, 51000, 476, 25, 2024, 200000, false);

        $this->rapports()->enregistrer(Rapport::creer(
            self::REFERENCE,
            new \DateTimeImmutable('2026-08-14 10:00:00'),
            Source::Audit,
            InstantaneRapport::creer(
                $saisie,
                $restitution,
                ContexteCalcul::depuis(-0.20, 0.10, 17.22),
                Branding::parDefaut(),
                ReferenceRapport::depuis(self::REFERENCE),
                new \DateTimeImmutable('2026-08-14 10:00:00'),
            ),
            '/rapport/'.self::REFERENCE.'/pdf',
        ));

        $factory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        $session->set(AuditController::SESSION_RESULTAT, [
            'saisie' => $saisie,
            'restitution' => $restitution,
            'reference' => self::REFERENCE,
        ]);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function rapports(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }
}
