<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Page « Mon compte » (#206, AUTH-3) : changement de mot de passe partagé par les
 * deux zones (espace pro app. et admin interne admin.) — même route, même page.
 */
final class MonCompteControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const APP = 'http://app.valeurverte.localhost';
    private const ADMIN = 'http://admin.valeurverte.localhost';
    private const ACTUEL = 'MotDePasseActuel1';

    public function testLaPageEstServieDansLEspaceProEtDansLAdmin(): void
    {
        // Même page, deux zones : un pro sur app., un admin sur admin.
        $clientPro = static::createClient();
        $clientPro->loginUser($this->seedUser('pro-compte@example.com', ['ROLE_PRO']));
        $clientPro->request('GET', self::APP.'/mon-compte');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="actuel"]');
        self::assertSelectorExists('input[name="nouveau"]');

        self::ensureKernelShutdown();
        $clientAdmin = static::createClient();
        $admin = $this->seedUser('admin-compte@example.com', ['ROLE_ADMIN']);
        $clientAdmin->loginUser($admin);
        $this->passerLeSecondFacteur($clientAdmin, $admin);
        $clientAdmin->request('GET', self::ADMIN.'/mon-compte');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="nouveau"]');
    }

    public function testUnMotDePasseValideEstChangeEtLaSessionCouranteEstConservee(): void
    {
        $client = static::createClient();
        $user = $this->seedUser('pro-change@example.com', ['ROLE_PRO']);
        $client->loginUser($user);

        $this->soumettre($client, self::ACTUEL, 'MonNouveauMotDePasse2', 'MonNouveauMotDePasse2');

        self::assertResponseRedirects(self::APP.'/mon-compte');
        // La session courante survit au changement (sinon → redirigé vers /login).
        $client->request('GET', self::APP.'/mon-compte');
        self::assertResponseIsSuccessful();
        // Le mot de passe est bien changé en base.
        $recharge = $this->users()->findOneBy(['email' => 'pro-change@example.com']);
        self::assertNotNull($recharge);
        self::assertTrue($this->hasher()->isPasswordValid($recharge, 'MonNouveauMotDePasse2'));
    }

    public function testUnMotDePasseActuelIncorrectEstRefuse(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('pro-faux@example.com', ['ROLE_PRO']));

        $this->soumettre($client, 'PasLeBon9', 'MonNouveauMotDePasse2', 'MonNouveauMotDePasse2');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error');
        $recharge = $this->users()->findOneBy(['email' => 'pro-faux@example.com']);
        self::assertNotNull($recharge);
        self::assertTrue($this->hasher()->isPasswordValid($recharge, self::ACTUEL));
    }

    public function testUnNouveauMotDePasseTropCourtEstRefuse(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('pro-court@example.com', ['ROLE_PRO']));

        $this->soumettre($client, self::ACTUEL, '123', '123');

        self::assertResponseStatusCodeSame(422);
    }

    public function testLaPageExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', self::APP.'/mon-compte');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testLaSidebarProPointeVersMonCompte(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('pro-nav@example.com', ['ROLE_PRO']));

        $client->request('GET', self::APP.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside a[href="/mon-compte"]');
    }

    public function testLaSidebarAdminPointeVersMonCompte(): void
    {
        $client = static::createClient();
        $admin = $this->seedUser('admin-nav@example.com', ['ROLE_ADMIN']);
        $client->loginUser($admin);
        $this->passerLeSecondFacteur($client, $admin);

        $client->request('GET', self::ADMIN.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside a[href="/mon-compte"]');
    }

    private function soumettre(KernelBrowser $client, string $actuel, string $nouveau, string $confirmation): void
    {
        $crawler = $client->request('GET', self::APP.'/mon-compte');
        $formulaire = $crawler->selectButton('Changer le mot de passe')->form();
        $formulaire['actuel'] = $actuel;
        $formulaire['nouveau'] = $nouveau;
        $formulaire['confirmation'] = $confirmation;
        $client->submit($formulaire);
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles): User
    {
        $user = new User($email, $roles);
        if (!\in_array('ROLE_ADMIN', $roles, true)) {
            $user->rattacherAuTenant(4242);
        }
        $user->definirMotDePasse($this->hasher()->hashPassword($user, self::ACTUEL));
        $this->users()->enregistrer($user);

        return $user;
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
