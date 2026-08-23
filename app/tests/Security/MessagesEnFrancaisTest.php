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
 * Les messages d'échec de connexion s'affichent en français (#318). Ils viennent des
 * exceptions de sécurité Symfony, dont la clé est un libellé anglais ; le catalogue
 * `security.fr` (fourni par le composant) les traduit dès lors que le translator est
 * actif avec `default_locale: fr`. La page annonce `lang="fr"` : ses messages doivent
 * suivre.
 */
final class MessagesEnFrancaisTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        // login_throttling garde ses fenêtres dans un cache que la transaction de
        // test ne remonte pas : un run précédent laisserait le compte verrouillé.
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        self::ensureKernelShutdown();
    }

    public function testUnMauvaisIdentifiantEstRefuseEnFrancais(): void
    {
        $client = static::createClient();
        $this->creerPro('fr@example.com', 'le-bon-secret');

        $this->tenter($client, '198.51.100.10', 'fr@example.com', 'le-mauvais-secret');
        $client->followRedirect();

        self::assertSelectorTextContains('.alert', 'Identifiants invalides.');
    }

    public function testUnVerrouillageEstAnnonceEnFrancais(): void
    {
        $client = static::createClient();
        $this->creerPro('fr2@example.com', 'le-bon-secret');

        for ($essai = 0; $essai < 6; ++$essai) {
            $this->tenter($client, '198.51.100.11', 'fr2@example.com', 'le-mauvais-secret');
        }
        $client->followRedirect();

        self::assertSelectorTextContains('.alert', 'Plusieurs tentatives de connexion ont échoué');
        self::assertSelectorTextContains('.alert', '15 minutes');
    }

    /**
     * @param non-empty-string $email
     */
    private function creerPro(string $email, string $motDePasse): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $utilisateurs = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);

        $user = new User($email, ['ROLE_PRO']);
        $user->definirMotDePasse($hasher->hashPassword($user, $motDePasse));
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
