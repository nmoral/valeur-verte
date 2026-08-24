<?php

declare(strict_types=1);

namespace App\Tests\Registration;

use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\StatutRge;
use App\Controller\PagesLegalesController;
use App\Security\UserRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class RegistrationControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';
    private const SIRET_CONNU = '12345678901234';
    private const SIRET_INCONNU = '00000000000000';

    public function testLaPageInscriptionEstPubliqueEtPorteLeFormulaire(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('input[name="password"]');
        self::assertSelectorExists('input[name="siret"]');
    }

    public function testLaPageInscriptionPorteLApercuEntrepriseEtSonScript(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#entreprise-apercu');
        self::assertSelectorExists('script[src^="/js/inscription-siret.js"]');
    }

    public function testLeFormulairePorteLAcceptationDesConditionsAvecSesLiens(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/inscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="checkbox"][name="cgu"]');
        // La case doit renvoyer aux deux textes qu'elle fait accepter.
        $liens = $crawler->filter('label[for="cgu"] a, .field-cgu a')->extract(['href']);
        self::assertContains('/cgu', $liens);
        self::assertContains('/cgv', $liens);
    }

    public function testUneInscriptionSansAccepterLesConditionsEstRefusee(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'refus-cgu@example.com', 'motdepasse-solide', '12345678901234', accepterCgu: false);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error');

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        self::assertNull(
            $repository->findOneBy(['email' => 'refus-cgu@example.com']),
            'Sans acceptation des conditions, aucun compte ne doit être créé.',
        );
    }

    public function testUneInscriptionValideEnregistreLaVersionDesConditionsAcceptee(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'consent@example.com', 'motdepasse-solide', '12345678901234');

        self::assertResponseRedirects('http://app.'.self::HOTE.'/audit');

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $user = $repository->findOneBy(['email' => 'consent@example.com']);
        self::assertNotNull($user);
        self::assertTrue($user->aAccepteLesConditions(), 'L\'acceptation doit être tracée.');
        self::assertSame(PagesLegalesController::VERSION_CGU, $user->versionConditionsAcceptee());
        self::assertNotNull($user->conditionsAccepteesLe());
    }

    public function testUneInscriptionValideCreeLeCompteEtConnecte(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'nouveau@example.com', 'motdepasse-solide', '12345678901234');

        // ONB-4 : l'inscription mène droit à la saisie, sans écran de bienvenue.
        self::assertResponseRedirects('http://app.'.self::HOTE.'/audit');

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $user = $repository->findOneBy(['email' => 'nouveau@example.com']);
        self::assertNotNull($user, 'Le compte doit être créé.');
        self::assertContains('ROLE_PRO', $user->getRoles());

        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Nouvel audit');
    }

    public function testUnEmailDejaUtiliseEstRefuse(): void
    {
        $client = static::createClient();
        $this->soumettreInscription($client, 'doublon@example.com', 'motdepasse-solide', '12345678901234');

        $this->soumettreInscription($client, 'doublon@example.com', 'un-autre-mot-de-passe', '12345678901234');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error');
    }

    public function testUneVarianteDUnEmailDejaUtiliseEstRefusee(): void
    {
        $client = static::createClient();
        $this->soumettreInscription($client, 'p.r.o@gmail.com', 'motdepasse-solide', '12345678901234');

        // Même boîte, quatre écritures : casse, alias « +tag », points et googlemail.
        // Sans quoi un seul pro s'offre autant d'essais gratuits qu'il a d'alias (#331).
        $this->soumettreInscription($client, 'P.R.O+Essai2@GoogleMail.com', 'un-autre-mot-de-passe', '12345678901234');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error');
    }

    public function testUnMotDePasseTropCourtEstRefuse(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'court@example.com', '123', '12345678901234');

        self::assertResponseStatusCodeSame(422);
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        self::assertNull($repository->findOneBy(['email' => 'court@example.com']));
    }

    public function testUneInscriptionAvecSiretConnuPersisteLaFicheLieeAuTenant(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'artisan@example.com', 'motdepasse-solide', self::SIRET_CONNU);

        // ONB-4 : l'inscription mène droit à la saisie, sans écran de bienvenue.
        self::assertResponseRedirects('http://app.'.self::HOTE.'/audit');

        $utilisateurs = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);
        $user = $utilisateurs->findOneBy(['email' => 'artisan@example.com']);
        self::assertNotNull($user);

        $entreprises = self::getContainer()->get(EntrepriseRepository::class);
        self::assertInstanceOf(EntrepriseRepository::class, $entreprises);
        $entreprise = $entreprises->findOneBy(['siret' => self::SIRET_CONNU]);
        self::assertInstanceOf(Entreprise::class, $entreprise);
        self::assertSame('Renov Habitat', $entreprise->raisonSociale());
        self::assertSame(StatutRge::Rge, $entreprise->statutRge());
        self::assertSame($user->getTenantId(), $entreprise->getTenantId());
    }

    public function testUneInscriptionAvecSiretInconnuNeCreePasDeFicheMaisLeCompte(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'sans-fiche@example.com', 'motdepasse-solide', self::SIRET_INCONNU);

        // ONB-4 : l'inscription mène droit à la saisie, sans écran de bienvenue.
        self::assertResponseRedirects('http://app.'.self::HOTE.'/audit');

        $entreprises = self::getContainer()->get(EntrepriseRepository::class);
        self::assertInstanceOf(EntrepriseRepository::class, $entreprises);
        self::assertNull($entreprises->findOneBy(['siret' => self::SIRET_INCONNU]), 'Un SIRET inconnu ne doit pas créer de fiche, mais ne bloque pas le compte.');
    }

    public function testUneInscriptionEnvoieUnEmailDeVerification(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'a-verifier@example.com', 'motdepasse-solide', '12345678901234');

        $mailer = self::getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $mailer);
        $emails = $mailer->envoyes();
        self::assertCount(1, $emails);
        self::assertSame('a-verifier@example.com', $emails[0]->destinataire());
        self::assertStringContainsString('/verification-email?utilisateur=', $emails[0]->corpsHtml());
    }

    private function soumettreInscription(KernelBrowser $client, string $email, string $motDePasse, string $siret, bool $accepterCgu = true): void
    {
        // Ces tests ne portent pas sur le rate limiting : on repart d'une fenêtre
        // vierge pour l'IP par défaut afin de ne pas fuir d'un run à l'autre.
        $limiteur = self::getContainer()->get('limiter.inscription_ip');
        self::assertInstanceOf(RateLimiterFactory::class, $limiteur);
        $limiteur->create('127.0.0.1')->reset();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/inscription');
        self::assertInstanceOf(Crawler::class, $crawler);
        $formulaire = $crawler->selectButton('Créer mon compte')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $formulaire['siret'] = $siret;
        if ($accepterCgu) {
            $caseCgu = $formulaire['cgu'];
            self::assertInstanceOf(ChoiceFormField::class, $caseCgu);
            $caseCgu->tick();
        }
        $client->submit($formulaire);
    }
}
