<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Signalement;

use App\AdminInterne\Signalement\Signalement;
use App\AdminInterne\Signalement\SignalementRepository;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SignalementsControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaPageSignalementsExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/signalements');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageListeLesSignalementsRecentsAvecContexte(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->signalements()->enregistrer(Signalement::creer(
            adresse: '12 rue des Lilas, 31000 Toulouse',
            contexte: ['dpe_avant' => 'E', 'dpe_apres' => 'C', 'n' => 134],
            message: 'Gain trop élevé pour ce quartier',
            signaleLe: new \DateTimeImmutable('2026-08-13 14:00:00'),
        ));

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/signalements');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Signalements');
        self::assertSelectorTextContains('tbody', '12 rue des Lilas, 31000 Toulouse');
        self::assertSelectorTextContains('tbody', 'Gain trop élevé pour ce quartier');
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

    private function signalements(): SignalementRepository
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(Signalement::class);
        self::assertInstanceOf(SignalementRepository::class, $repository);

        return $repository;
    }
}
