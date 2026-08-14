<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne;

use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConfigurationControllerTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaConfigurationExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/configuration');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageRegroupeLesConfigsAvecLeursValeursCourantes(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/configuration');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Configuration');

        // Section seuil d'essai (#47) : formulaire posté vers sa route, valeur courante.
        self::assertSelectorExists('form[action="/comptes/seuil"] input[name="seuil"][value="10"]');
        // Section seuils d'alerte (#45) : formulaire posté vers sa route, valeurs courantes.
        self::assertSelectorExists('form[action="/coefficients/seuils"] input[name="erreur_mediane_max"][value="30"]');
        self::assertSelectorExists('form[action="/coefficients/seuils"] input[name="gain_ec_max"][value="0.7"]');
        // Chaque formulaire est marqué pour la soumission asynchrone.
        self::assertSelectorExists('form[data-async][action="/comptes/seuil"]');
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }
}
