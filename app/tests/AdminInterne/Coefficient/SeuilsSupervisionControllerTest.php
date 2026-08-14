<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Coefficient;

use App\AdminInterne\Coefficient\SeuilsSupervision;
use App\AdminInterne\Coefficient\SeuilsSupervisionRepository;
use App\Security\User;
use App\Security\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SeuilsSupervisionControllerTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLEditionDesSeuilsExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/coefficients/seuils');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageAfficheLesSeuilsCourants(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/coefficients/seuils');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Seuils d'alerte");
        self::assertSelectorExists('input[name="erreur_mediane_max"][value="30"]');
        self::assertSelectorExists('input[name="gain_ec_max"][value="0.7"]');
    }

    public function testPosterDeNouveauxSeuilsLesMetAJourEtRedirige(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/coefficients/seuils', [
            'erreur_mediane_max' => '25',
            'gain_ec_max' => '0.6',
        ]);

        self::assertResponseRedirects('http://'.self::HOTE_ADMIN.'/coefficients/seuils');
        $courants = $this->seuils()->courants();
        self::assertSame(25.0, $courants->erreurMedianeMaxPct());
        self::assertSame(0.6, $courants->gainEcMaxLog());
    }

    public function testDesSeuilsInvalidesSontRefusesSansRienChanger(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seuils()->enregistrer(SeuilsSupervision::de(30.0, 0.70));

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/coefficients/seuils', [
            'erreur_mediane_max' => '0',
            'gain_ec_max' => '0.6',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(30.0, $this->seuils()->courants()->erreurMedianeMaxPct());
    }

    public function testUnPostAsyncValideRenvoieUnJsonOk(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->xmlHttpRequest('POST', 'http://'.self::HOTE_ADMIN.'/coefficients/seuils', [
            'erreur_mediane_max' => '22',
            'gain_ec_max' => '0.5',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertTrue($charge['ok']);
        self::assertSame(22.0, $this->seuils()->courants()->erreurMedianeMaxPct());
    }

    public function testUnPostAsyncInvalideRenvoieUnJson422(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seuils()->enregistrer(SeuilsSupervision::de(30.0, 0.70));

        $client->xmlHttpRequest('POST', 'http://'.self::HOTE_ADMIN.'/coefficients/seuils', [
            'erreur_mediane_max' => '0',
            'gain_ec_max' => '0.5',
        ]);

        self::assertResponseStatusCodeSame(422);
        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertFalse($charge['ok']);
        self::assertArrayHasKey('erreur', $charge);
        self::assertSame(30.0, $this->seuils()->courants()->erreurMedianeMaxPct());
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function seuils(): SeuilsSupervisionRepository
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(SeuilsSupervision::class);
        self::assertInstanceOf(SeuilsSupervisionRepository::class, $repository);

        return $repository;
    }
}
