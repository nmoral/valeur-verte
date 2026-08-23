<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\ChangementEmail\DemandeChangementEmail;
use App\Security\ChangementEmail\DemandeChangementEmailRepository;
use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Changement d'adresse email de bout en bout (#310) — le critère d'acceptation
 * de l'issue : « un pro change d'adresse email seul, sans perdre l'accès à son
 * compte, et l'ancienne adresse en est informée ».
 */
final class ChangementEmailControllerTest extends WebTestCase
{
    private const APP = 'http://app.valeurverte.localhost';

    public function testLeFormulaireDeChangementDAdresseEstSurMonCompte(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('pro-formulaire@example.com'));

        $client->request('GET', self::APP.'/mon-compte');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="nouvel_email"]');
    }

    public function testUnProChangeDAdresseSeulEtSansPerdreLAccesASonCompte(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('avant-bout-en-bout@example.com'));

        // 1. Il demande le changement depuis « Mon compte ».
        $this->demander($client, 'apres-bout-en-bout@example.com');
        self::assertResponseRedirects(self::APP.'/mon-compte');

        // 2. L'ancienne adresse est informée — le garde-fou de l'issue. Elle reçoit
        // l'adresse visée, sans le lien qui permettrait de valider la bascule.
        $avertissement = $this->emailVers('avant-bout-en-bout@example.com');
        self::assertStringContainsString('apres-bout-en-bout@example.com', $avertissement->corpsHtml());
        self::assertStringNotContainsString('/mon-compte/email/confirmer', $avertissement->corpsHtml());

        // 3. Il clique le lien reçu à la nouvelle adresse.
        $client->request('GET', self::APP.$this->lienRecuPar('apres-bout-en-bout@example.com'));
        self::assertResponseIsSuccessful();

        // 4. Le compte a basculé, et la session en cours n'est pas tombée.
        $client->request('GET', self::APP.'/mon-compte');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth-lead', 'apres-bout-en-bout@example.com');
    }

    public function testLaConfirmationSAtteintSansEtreConnecte(): void
    {
        // Le lien est cliqué depuis la nouvelle boîte, souvent sur un autre
        // appareil : une redirection vers /login le rendrait inutilisable.
        $client = static::createClient();
        $user = $this->seedUser('avant-sans-session@example.com');
        $jetonBrut = $this->seedDemande($user, 'apres-sans-session@example.com');

        $client->request('GET', self::APP.'/mon-compte/email/confirmer?jeton='.$jetonBrut);

        self::assertResponseIsSuccessful();
        $recharge = $this->users()->find($user->getId());
        self::assertNotNull($recharge);
        self::assertSame('apres-sans-session@example.com', $recharge->getEmail());
    }

    public function testLaNouvelleAdresseConnecteEtLAncienneNeConnectePlus(): void
    {
        $client = static::createClient();
        $user = $this->seedUser('avant-login@example.com', 'MonMotDePasse1');
        $jetonBrut = $this->seedDemande($user, 'apres-login@example.com');
        $client->request('GET', self::APP.'/mon-compte/email/confirmer?jeton='.$jetonBrut);

        $this->seConnecter($client, 'apres-login@example.com', 'MonMotDePasse1');
        self::assertResponseRedirects();

        $this->seConnecter($client, 'avant-login@example.com', 'MonMotDePasse1');
        self::assertResponseRedirects(self::APP.'/login');
    }

    public function testUnJetonInvalideRendUnePageDeRefusSansRienChanger(): void
    {
        $client = static::createClient();
        $user = $this->seedUser('avant-refus@example.com');

        $client->request('GET', self::APP.'/mon-compte/email/confirmer?jeton='.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
        $recharge = $this->users()->find($user->getId());
        self::assertNotNull($recharge);
        self::assertSame('avant-refus@example.com', $recharge->getEmail());
    }

    public function testUneAdresseDejaPriseEstRefuseeSurLeFormulaire(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('pro-prise@example.com'));
        $this->seedUser('deja-prise@example.com');

        $this->demander($client, 'deja-prise@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error');
        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testUneAdresseMalFormeeEstRefuseeSurLeFormulaire(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('pro-format@example.com'));

        $this->demander($client, 'pas-une-adresse');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error');
        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testUnJetonCsrfAbsentEstRefuse(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('pro-csrf@example.com'));

        $client->request('POST', self::APP.'/mon-compte/email', ['nouvel_email' => 'apres-csrf@example.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testLaDemandeExigeUneSession(): void
    {
        $client = static::createClient();

        $client->request('POST', self::APP.'/mon-compte/email', ['nouvel_email' => 'apres-anonyme@example.com']);

        self::assertResponseRedirects(self::APP.'/login');
    }

    private function demander(KernelBrowser $client, string $nouvelEmail): void
    {
        $crawler = $client->request('GET', self::APP.'/mon-compte');
        $jeton = $crawler->filter('input[name="_csrf_token"]')->last()->attr('value');
        $client->request('POST', self::APP.'/mon-compte/email', [
            'nouvel_email' => $nouvelEmail,
            '_csrf_token' => (string) $jeton,
        ]);
    }

    private function seConnecter(KernelBrowser $client, string $email, string $motDePasse): void
    {
        $crawler = $client->request('GET', self::APP.'/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $motDePasse,
        ]));
    }

    /**
     * Chemin (avec sa requête) du lien de confirmation reçu à une adresse.
     */
    private function lienRecuPar(string $destinataire): string
    {
        $corps = $this->emailVers($destinataire)->corpsHtml();
        if (1 !== preg_match('#(/mon-compte/email/confirmer\?jeton=[0-9a-f]{64})#', $corps, $m)) {
            self::fail('Lien de confirmation introuvable dans l\'email.');
        }

        return $m[1];
    }

    private function emailVers(string $destinataire): \App\Notification\Email
    {
        foreach ($this->mailer()->envoyes() as $email) {
            if ($destinataire === $email->destinataire()) {
                return $email;
            }
        }

        self::fail(\sprintf('Aucun email envoyé à « %s ».', $destinataire));
    }

    private function seedDemande(User $user, string $nouvelEmail): string
    {
        $userId = $user->getId();
        self::assertNotNull($userId);

        $generateur = new GenerateurJeton();
        $jetonBrut = $generateur->jetonBrut();
        $demandes = self::getContainer()->get(DemandeChangementEmailRepository::class);
        self::assertInstanceOf(DemandeChangementEmailRepository::class, $demandes);
        $demandes->enregistrer(new DemandeChangementEmail(
            $userId,
            $nouvelEmail,
            $generateur->empreinte($jetonBrut),
            new \DateTimeImmutable('+1 hour'),
        ));

        return $jetonBrut;
    }

    private function seedUser(string $email, string $motDePasse = 'MotDePasseActuel1'): User
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        self::assertInstanceOf(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class, $hasher);
        $user->definirMotDePasse($hasher->hashPassword($user, $motDePasse));
        $this->users()->enregistrer($user);

        return $user;
    }

    private function mailer(): MailerEnMemoire
    {
        $mailer = self::getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $mailer);

        return $mailer;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
