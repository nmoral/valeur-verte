<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\User;
use App\Security\UserRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Force brute sur `/login` (#307). L'inscription (5/h par IP) et la page publique
 * (20/15 min) ont leur plafond ; le formulaire de connexion n'en avait aucun, alors
 * qu'il ouvre selon le rôle l'espace d'un pro **ou** l'admin interne — et que les
 * adresses des comptes ne sont pas secrètes.
 *
 * Le plafond porte sur le couple **(IP, identifiant)** : plafonner la seule IP
 * laisserait un attaquant distribué passer, et plafonner le seul identifiant
 * permettrait de verrouiller à distance le compte de n'importe qui.
 *
 * Chaque test se donne son IP : les fenêtres du limiteur vivent dans un cache que la
 * transaction de test ne remonte pas.
 */
final class ForceBruteLoginTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';
    private const MOT_DE_PASSE = 'secret-solide';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        // Les fenêtres du limiteur vivent dans un cache que la transaction de test ne
        // remonte pas : sans purge, un second passage de la suite démarrerait déjà
        // verrouillé.
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        self::ensureKernelShutdown();
    }

    public function testAuDelaDuPlafondMemeLeBonMotDePasseEstRefuse(): void
    {
        $client = static::createClient();
        $this->creerPro('cible@example.com');

        for ($essai = 0; $essai < 5; ++$essai) {
            $this->tenter($client, '203.0.113.10', 'cible@example.com', 'mauvais-mot-de-passe');
        }

        $this->tenter($client, '203.0.113.10', 'cible@example.com', self::MOT_DE_PASSE);

        // Le bon mot de passe ne suffit plus : la fenêtre est fermée.
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('/login', $client->getRequest()->getUri());
        // L'utilisateur doit savoir combien de temps il est bloqué : un refus sans
        // horizon se lit comme un compte cassé.
        self::assertSelectorTextContains('.alert', '15');
    }

    public function testSousLePlafondLaConnexionResteNormale(): void
    {
        $client = static::createClient();
        $this->creerPro('patient@example.com');

        for ($essai = 0; $essai < 4; ++$essai) {
            $this->tenter($client, '203.0.113.11', 'patient@example.com', 'mauvais-mot-de-passe');
        }

        $this->tenter($client, '203.0.113.11', 'patient@example.com', self::MOT_DE_PASSE);

        self::assertResponseRedirects('http://'.self::HOTE_PRO.'/');
    }

    public function testVerrouillerUnCompteNeVerrouillePasLesAutres(): void
    {
        $client = static::createClient();
        $this->creerPro('assiege@example.com');
        $this->creerPro('voisin@example.com');

        for ($essai = 0; $essai < 6; ++$essai) {
            $this->tenter($client, '203.0.113.12', 'assiege@example.com', 'mauvais-mot-de-passe');
        }

        // Même IP, autre compte : il garde sa propre fenêtre.
        $this->tenter($client, '203.0.113.12', 'voisin@example.com', self::MOT_DE_PASSE);

        self::assertResponseRedirects('http://'.self::HOTE_PRO.'/');
    }

    public function testChangerDAliasNeRouvrePasUneFenetreFermee(): void
    {
        $client = static::createClient();
        $this->creerPro('assailli@gmail.com');

        for ($essai = 0; $essai < 5; ++$essai) {
            $this->tenter($client, '203.0.113.13', 'assailli@gmail.com', 'mauvais-mot-de-passe');
        }

        // Autre écriture, même compte (#331) : depuis que la connexion résout
        // l'identité, un alias ouvrirait sinon un compteur neuf — et le plafond de
        // cinq essais se multiplierait par le nombre d'alias imaginables.
        $this->tenter($client, '203.0.113.13', 'A.S.S.A.I.L.L.I+Bis@GoogleMail.com', self::MOT_DE_PASSE);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('/login', $client->getRequest()->getUri());
    }

    /**
     * @param non-empty-string $email
     */
    private function creerPro(string $email): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $utilisateurs = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);

        $user = new User($email, ['ROLE_PRO']);
        $user->definirMotDePasse($hasher->hashPassword($user, self::MOT_DE_PASSE));
        $utilisateurs->enregistrer($user);
    }

    private function tenter(KernelBrowser $client, string $ip, string $email, string $motDePasse): void
    {
        $serveur = ['REMOTE_ADDR' => $ip];
        $crawler = $client->request('GET', 'http://'.self::HOTE_PRO.'/login', [], [], $serveur);
        $formulaire = $crawler->selectButton('Se connecter')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $client->submit($formulaire, [], $serveur);
    }
}
