<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\SeuilEssai;
use App\AdminInterne\Compte\SeuilEssaiRepository;
use App\Security\User;
use App\Security\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SeuilEssaiControllerTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLEditionDuSeuilExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/comptes/seuil');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageAfficheLeSeuilCourant(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/comptes/seuil');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Seuil d'essai");
        self::assertSelectorExists('input[name="seuil"][value="10"]');
    }

    public function testPosterUnNouveauSeuilLeMetAJourEtRedirige(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/comptes/seuil', ['seuil' => '25']);

        self::assertResponseRedirects('http://'.self::HOTE_ADMIN.'/comptes/seuil');
        self::assertSame(25, $this->seuils()->courant()->valeur());
    }

    public function testUnSeuilInvalideEstRefuseSansRienChanger(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seuils()->enregistrer(SeuilEssai::de(10));

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/comptes/seuil', ['seuil' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(10, $this->seuils()->courant()->valeur());
    }

    public function testUnPostAsyncValideRenvoieUnJsonOk(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->xmlHttpRequest('POST', 'http://'.self::HOTE_ADMIN.'/comptes/seuil', ['seuil' => '15']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertTrue($charge['ok']);
        self::assertSame(15, $this->seuils()->courant()->valeur());
    }

    public function testUnPostAsyncInvalideRenvoieUnJson422(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seuils()->enregistrer(SeuilEssai::de(10));

        $client->xmlHttpRequest('POST', 'http://'.self::HOTE_ADMIN.'/comptes/seuil', ['seuil' => '0']);

        self::assertResponseStatusCodeSame(422);
        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertFalse($charge['ok']);
        self::assertArrayHasKey('erreur', $charge);
        self::assertSame(10, $this->seuils()->courant()->valeur());
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function seuils(): SeuilEssaiRepository
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(SeuilEssai::class);
        self::assertInstanceOf(SeuilEssaiRepository::class, $repository);

        return $repository;
    }
}
