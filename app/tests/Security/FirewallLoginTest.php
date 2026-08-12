<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FirewallLoginTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLEspaceProExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_PRO.'/');

        self::assertResponseRedirects('/login');
    }

    public function testLAdminInterneExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageDeLoginEstPubliqueEtPorteLeFormulaire(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_PRO.'/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('input[name="password"]');
    }

    public function testUnProEstRedirigeVersSonEspaceApresConnexion(): void
    {
        $client = static::createClient();
        $this->creerUtilisateur('pro@example.com', 'secret-pro', ['ROLE_PRO']);

        $this->seConnecter($client, self::HOTE_PRO, 'pro@example.com', 'secret-pro');

        self::assertResponseRedirects('http://'.self::HOTE_PRO.'/');
    }

    public function testUnAdminEstRedirigeVersLAdminApresConnexion(): void
    {
        $client = static::createClient();
        $this->creerUtilisateur('admin@example.com', 'secret-admin', ['ROLE_ADMIN']);

        $this->seConnecter($client, self::HOTE_ADMIN, 'admin@example.com', 'secret-admin');

        self::assertResponseRedirects('http://'.self::HOTE_ADMIN.'/');
    }

    public function testUnProNePeutPasAccederALAdminInterne(): void
    {
        $client = static::createClient();
        $this->creerUtilisateur('pro2@example.com', 'secret-pro', ['ROLE_PRO']);
        $this->seConnecter($client, self::HOTE_PRO, 'pro2@example.com', 'secret-pro');
        $client->followRedirect();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $roles
     */
    private function creerUtilisateur(string $email, string $motDePasse, array $roles): void
    {
        $container = self::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        $user = new User($email, $roles);
        $user->definirMotDePasse($hasher->hashPassword($user, $motDePasse));
        $repository->enregistrer($user);
    }

    private function seConnecter(KernelBrowser $client, string $hote, string $email, string $motDePasse): void
    {
        $crawler = $client->request('GET', 'http://'.$hote.'/login');
        $formulaire = $crawler->selectButton('Se connecter')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $client->submit($formulaire);
    }
}
