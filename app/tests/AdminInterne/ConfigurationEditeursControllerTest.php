<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne;

use App\Compte\DelaiRelanceEssaiRepository;
use App\Compte\Formule;
use App\Compte\ReglagesFormuleRepository;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Édition des réglages d'essai depuis la page de configuration (#61/#108) :
 * paramètres de formule et délai de relance, en async. Zone « admin. », ROLE_ADMIN.
 */
final class ConfigurationEditeursControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testEditerLeDelaiDeRelance(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->xmlHttpRequest('POST', 'http://'.self::HOTE_ADMIN.'/configuration/delai-relance', ['delai' => '5']);

        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->delais()->courant()->jours());
    }

    public function testEditerLesParametresDUneFormule(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->xmlHttpRequest('POST', 'http://'.self::HOTE_ADMIN.'/configuration/formule', [
            'formule' => 'classique',
            'duree' => '90',
            'quota' => '15',
            'plafond' => '0',
        ]);

        self::assertResponseIsSuccessful();
        $parametres = $this->reglages()->pour(Formule::Classique);
        self::assertSame(90, $parametres->dureeEssaiJours());
        self::assertSame(15, $parametres->quotaRapports());
        self::assertNull($parametres->plafondComptes());
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
        $this->passerLeSecondFacteur($client, $admin);
    }

    private function delais(): DelaiRelanceEssaiRepository
    {
        $repository = self::getContainer()->get(DelaiRelanceEssaiRepository::class);
        self::assertInstanceOf(DelaiRelanceEssaiRepository::class, $repository);

        return $repository;
    }

    private function reglages(): ReglagesFormuleRepository
    {
        $repository = self::getContainer()->get(ReglagesFormuleRepository::class);
        self::assertInstanceOf(ReglagesFormuleRepository::class, $repository);

        return $repository;
    }
}
