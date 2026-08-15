<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\GrilleCout\Poste;
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
 * Déclarations du pro sur la restitution (#26) : situation de revenus du ménage et
 * retouches de chiffrage, modifiables après coup sans réémettre de rapport.
 *
 * La catégorie est **déclarative** : le pro la choisit, aidé du tableau des plafonds
 * affiché à côté. Elle ne préjuge d'aucune éligibilité instruite par l'Anah.
 */
final class AuditResultatDeclarationsTest extends WebTestCase
{
    private const string REFERENCE = 'VV-20260815-DC9999';

    public function testLaRestitutionOffreLeChoixDeLaSituationDuMenage(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $client->request('GET', 'http://valeurverte.localhost/audit/resultat');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/audit/resultat/declarations"] [name="categorie_revenu"]');
        // Les plafonds officiels dépendent du foyer et de la région : on les montre
        // plutôt que de faire deviner la tranche.
        self::assertSelectorTextContains('body', 'revenu fiscal de référence');
    }

    public function testDeclarerUneCategorieLaPersisteSurLeRapport(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $client->request('POST', 'http://valeurverte.localhost/audit/resultat/declarations', [
            'categorie_revenu' => CategorieRevenu::Modeste->name,
        ]);

        self::assertResponseStatusCodeSame(302);
        $rapport = $this->rapports()->parReference(self::REFERENCE);
        self::assertInstanceOf(Rapport::class, $rapport);
        self::assertSame(CategorieRevenu::Modeste, $rapport->declarations()->categorieRevenu());
    }

    public function testRetoucherUnPosteDeChiffrageLePersiste(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $client->request('POST', 'http://valeurverte.localhost/audit/resultat/declarations', [
            'categorie_revenu' => CategorieRevenu::Modeste->name,
            'chiffrage' => [Poste::Menuiseries->name => '8400'],
        ]);

        self::assertResponseStatusCodeSame(302);
        $rapport = $this->rapports()->parReference(self::REFERENCE);
        self::assertInstanceOf(Rapport::class, $rapport);
        self::assertTrue($rapport->declarations()->aDesRetouches());
    }

    public function testUneCategorieInconnueEstRefuseeSansRienEcrire(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $client->request('POST', 'http://valeurverte.localhost/audit/resultat/declarations', [
            'categorie_revenu' => 'Fortune',
        ]);

        self::assertResponseStatusCodeSame(422);
        $rapport = $this->rapports()->parReference(self::REFERENCE);
        self::assertInstanceOf(Rapport::class, $rapport);
        self::assertSame(CategorieRevenu::NonPrecisee, $rapport->declarations()->categorieRevenu());
    }

    public function testUneRetoucheNegativeEstRefuseeSansRienEcrire(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $client->request('POST', 'http://valeurverte.localhost/audit/resultat/declarations', [
            'categorie_revenu' => CategorieRevenu::Modeste->name,
            'chiffrage' => [Poste::Menuiseries->name => '-1'],
        ]);

        self::assertResponseStatusCodeSame(422);
        $rapport = $this->rapports()->parReference(self::REFERENCE);
        self::assertInstanceOf(Rapport::class, $rapport);
        self::assertSame(CategorieRevenu::NonPrecisee, $rapport->declarations()->categorieRevenu());
    }

    public function testDeclarerNAlterePasLaMesureFigee(): void
    {
        $client = static::createClient();
        $this->poserContexte($client);

        $client->request('POST', 'http://valeurverte.localhost/audit/resultat/declarations', [
            'categorie_revenu' => CategorieRevenu::TresModeste->name,
        ]);

        $rapport = $this->rapports()->parReference(self::REFERENCE);
        self::assertInstanceOf(Rapport::class, $rapport);
        self::assertSame(40700, $rapport->instantane()->restitution()?->montantEuros());
        self::assertSame(40700, $rapport->valeurVerteEstimee());
    }

    public function testSansResultatEnSessionOnRenvoieVersLaSaisie(): void
    {
        $client = static::createClient();

        $client->request('POST', 'http://valeurverte.localhost/audit/resultat/declarations', [
            'categorie_revenu' => CategorieRevenu::Modeste->name,
        ]);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertTrue(str_ends_with($location, '/audit'), 'Doit rediriger vers la saisie, pas : '.$location);
    }

    private function poserContexte(KernelBrowser $client): void
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, 31000 Toulouse', 90, 300, Dpe::E, Dpe::C, 4);
        $restitution = Restitution::depuis(40700, 30000, 51000, 476, 25, 2024, 200000, false);
        $bouquet = Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries, Poste::Chauffage);

        $this->rapports()->enregistrer(Rapport::creer(
            self::REFERENCE,
            new \DateTimeImmutable('2026-08-15 10:00:00'),
            Source::Audit,
            InstantaneRapport::creer(
                $saisie,
                $restitution,
                ContexteCalcul::depuis(-0.20, 0.10, 17.22),
                Branding::parDefaut(),
                ReferenceRapport::depuis(self::REFERENCE),
                new \DateTimeImmutable('2026-08-15 10:00:00'),
                $bouquet,
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
            'bouquet' => $bouquet,
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
