<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Parcours « mot de passe oublié » (#194, AUTH-1) : demande depuis la connexion,
 * réponse neutre (anti-énumération), lien à usage unique reçu par email, puis
 * reconnexion avec le nouveau mot de passe. Aucun envoi réel sous test.
 */
final class MotDePasseOublieControllerTest extends WebTestCase
{
    private const HOTE = 'http://app.valeurverte.localhost';

    public function testLaPageDeConnexionPointeVersLeMotDePasseOublie(): void
    {
        $client = static::createClient();
        $client->request('GET', self::HOTE.'/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mot-de-passe-oublie"]');
    }

    public function testUnCompteExistantRecoitUnEmailEtVoitUneConfirmationNeutre(): void
    {
        $client = static::createClient();
        $this->seedUser('artisan-web@example.com', 'AncienMotDePasse1');

        $this->soumettreDemande($client, 'artisan-web@example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth-card', 'Si un compte existe');
        self::assertCount(1, $this->mailer()->envoyes());
    }

    public function testUnCompteInconnuVoitLaMemeConfirmationSansEmail(): void
    {
        $client = static::createClient();

        $this->soumettreDemande($client, 'personne@example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth-card', 'Si un compte existe');
        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testLeParcoursCompletReinitialiseEtPermetDeSeReconnecter(): void
    {
        $client = static::createClient();
        $this->seedUser('artisan-parcours@example.com', 'AncienMotDePasse1');

        $this->soumettreDemande($client, 'artisan-parcours@example.com');
        $lien = $this->lienDeReinitialisation();

        // Le formulaire de réinitialisation s'affiche pour un lien valide.
        $crawler = $client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        $formulaire = $crawler->selectButton('Définir le mot de passe')->form();
        $formulaire['password'] = 'MonNouveauMotDePasse2';
        $client->submit($formulaire);
        self::assertResponseRedirects();

        // Reconnexion avec le nouveau mot de passe : l'authentification réussit
        // (redirection hors de la page de connexion).
        $crawler = $client->request('GET', self::HOTE.'/login');
        $login = $crawler->selectButton('Se connecter')->form();
        $login['email'] = 'artisan-parcours@example.com';
        $login['password'] = 'MonNouveauMotDePasse2';
        $client->submit($login);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testUnLienInvalideAfficheUneErreur(): void
    {
        $client = static::createClient();

        $client->request('GET', self::HOTE.'/mot-de-passe-oublie/reinitialiser?jeton='.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaDemandeEstLimiteeParIp(): void
    {
        $client = static::createClient();
        $ip = '203.0.113.42';

        $fabrique = self::getContainer()->get('limiter.mot_de_passe_oublie_ip');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $fabrique);
        $limiteur = $fabrique->create($ip);
        $limiteur->reset();
        while ($limiteur->consume(1)->isAccepted()) {
        }

        $client->setServerParameter('REMOTE_ADDR', $ip);
        $this->soumettreDemande($client, 'artisan-limite@example.com');

        self::assertResponseStatusCodeSame(429);
    }

    private function soumettreDemande(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', self::HOTE.'/mot-de-passe-oublie');
        $formulaire = $crawler->selectButton('Envoyer le lien')->form();
        $formulaire['email'] = $email;
        $client->submit($formulaire);
    }

    private function lienDeReinitialisation(): string
    {
        $corps = $this->mailer()->envoyes()[0]->corpsHtml();
        self::assertSame(1, preg_match('#(/mot-de-passe-oublie/reinitialiser\?jeton=[0-9a-f]{64})#', $corps, $m));

        return self::HOTE.$m[1];
    }

    private function seedUser(string $email, string $motDePasse): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $user->definirMotDePasse($hasher->hashPassword($user, $motDePasse));
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $repository->enregistrer($user);
    }

    private function mailer(): MailerEnMemoire
    {
        $mailer = self::getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $mailer);

        return $mailer;
    }
}
