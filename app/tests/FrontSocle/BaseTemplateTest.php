<?php

declare(strict_types=1);

namespace App\Tests\FrontSocle;

use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Socle front (AssetMapper + Stimulus + UX LiveComponent) : le layout des zones
 * authentifiées émet l'importmap qui démarre Stimulus (support des LiveComponents),
 * sans casser le cache-busting par contenu des CSS legacy servis depuis public/.
 */
final class BaseTemplateTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLeLayoutEmetLImportmapPourDemarrerStimulus(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/journal');

        self::assertResponseIsSuccessful();
        $contenu = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('type="importmap"', $contenu);
    }

    public function testLeCacheBustingDesCssLegacyEstConserve(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/journal');

        self::assertResponseIsSuccessful();
        $contenu = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#/css/back-office\.css\?v=\w+#', $contenu);
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
