<?php

declare(strict_types=1);

namespace App\Tests\Controller\Compte;

use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Self-service de résiliation/effacement (#308, RGPD) : depuis « Mon compte », un
 * pro programme la suppression de son compte sans contact humain, protégée par son
 * mot de passe, et peut l'annuler tant que le délai de grâce n'a pas expiré.
 */
final class SuppressionCompteControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const APP = 'http://app.valeurverte.localhost';
    private const MOT_DE_PASSE = 'MotDePasseActuel1';

    public function testLaPageAfficheLaZoneDeSuppressionPourUnPro(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-suppr@example.com', 7001);

        $client->request('GET', self::APP.'/mon-compte/suppression');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="motdepasse"]');
        self::assertSelectorExists('button');
    }

    public function testLaPageAnnonceLEmpreinteConserveeApresEffacement(): void
    {
        // #332 : au moment de supprimer, la personne doit savoir ce qui subsiste
        // (une empreinte non réversible) et pourquoi (pas de nouvel essai à la
        // réinscription).
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-rgpd@example.com', 7006);

        $client->request('GET', self::APP.'/mon-compte/suppression');

        self::assertResponseIsSuccessful();
        $texte = mb_strtolower((string) $client->getCrawler()->filter('main')->text());
        self::assertStringContainsString('empreinte', $texte, 'L\'écran doit annoncer l\'empreinte conservée.');
        self::assertStringContainsString('essai', $texte, 'Il doit dire que la réinscription ne rouvre pas d\'essai.');
    }

    public function testUnMotDePasseValideProgrammeLaSuppression(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-ok@example.com', 7002);

        $this->demander($client, self::MOT_DE_PASSE);

        self::assertResponseRedirects();
        self::assertTrue($this->comptes()->pourTenant(7002)?->estEnAttenteDeSuppression());
    }

    public function testUnMotDePasseIncorrectNeProgrammePasLaSuppression(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-ko@example.com', 7003);

        $this->demander($client, 'PasLeBon9');

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->comptes()->pourTenant(7003)?->estEnAttenteDeSuppression());
    }

    public function testLaPageMontreLEtatEnAttenteEtPermetDAnnuler(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-annule@example.com', 7004);
        $this->demander($client, self::MOT_DE_PASSE);
        self::assertTrue($this->comptes()->pourTenant(7004)?->estEnAttenteDeSuppression());

        $crawler = $client->request('GET', self::APP.'/mon-compte/suppression');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Annuler la suppression')->form());

        self::assertResponseRedirects();
        self::assertFalse($this->comptes()->pourTenant(7004)?->estEnAttenteDeSuppression());
    }

    public function testLaPageMonCompteProposeLaSuppressionAuPro(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-lien@example.com', 7005);

        $client->request('GET', self::APP.'/mon-compte');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mon-compte/suppression"]');
    }

    public function testLAdminNeVoitPasLaSuppressionSurMonCompte(): void
    {
        $client = static::createClient();
        $admin = new User('admin-suppr@example.com', ['ROLE_ADMIN']);
        $admin->definirMotDePasse($this->hasher()->hashPassword($admin, self::MOT_DE_PASSE));
        $this->users()->enregistrer($admin);
        $client->loginUser($admin);
        $this->passerLeSecondFacteur($client, $admin);

        $client->request('GET', 'http://admin.valeurverte.localhost/mon-compte');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/mon-compte/suppression"]');
    }

    public function testLaPageExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', self::APP.'/mon-compte/suppression');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    private function demander(KernelBrowser $client, string $motDePasse): void
    {
        $crawler = $client->request('GET', self::APP.'/mon-compte/suppression');
        $formulaire = $crawler->selectButton('Supprimer mon compte')->form();
        $formulaire['motdepasse'] = $motDePasse;
        $client->submit($formulaire);
    }

    private function seedProAvecCompte(KernelBrowser $client, string $email, int $tenantId): void
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant($tenantId);
        $user->definirMotDePasse($this->hasher()->hashPassword($user, self::MOT_DE_PASSE));
        $this->users()->enregistrer($user);
        $this->comptes()->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable('2026-01-01')));
        $client->loginUser($user);
    }

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
