<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne;

use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\Formule;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Validation des candidatures fondateur côté éditeur (#61) : la page liste les
 * comptes en attente (avec de quoi les contacter) et permet de valider ou refuser.
 * Zone « admin. », ROLE_ADMIN.
 */
final class CandidaturesFondateurControllerTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaPageExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/comptes/candidatures');

        self::assertResponseRedirects('/login');
    }

    public function testElleListeLesCandidaturesEnAttenteAvecLeurContact(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seedCandidat('artisan-a@example.com', 9200);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/comptes/candidatures');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Candidatures');
        self::assertSelectorTextContains('tbody', 'artisan-a@example.com');
    }

    public function testValiderUneCandidatureBasculeLeCompteEnFondateur(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seedCandidat('artisan-b@example.com', 9201);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/comptes/candidatures', ['tenant' => '9201', 'action' => 'valider']);

        self::assertResponseStatusCodeSame(302);
        $compte = $this->comptes()->pourTenant(9201);
        self::assertNotNull($compte);
        self::assertSame(Formule::Fondateur, $compte->formule());
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $this->users()->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function seedCandidat(string $email, int $tenantId): void
    {
        $artisan = new User($email, ['ROLE_PRO']);
        $artisan->rattacherAuTenant($tenantId);
        $this->users()->enregistrer($artisan);
        $this->comptes()->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable('2026-02-01 09:00:00')));
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }
}
