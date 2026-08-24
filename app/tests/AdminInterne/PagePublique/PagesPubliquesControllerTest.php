<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\PagePublique;

use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran de pilotage des pages publiques côté admin interne (#268, épic #6).
 *
 * Le kill switch a besoin d'un endroit d'où l'actionner : la liste des pages, leur
 * état de service, et l'action de groupe qui coupe ou rétablit. Zone « admin. »,
 * réservée à ROLE_ADMIN comme les autres écrans internes.
 */
final class PagesPubliquesControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const string HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaPageExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/pages-publiques');

        self::assertResponseRedirects('/login');
    }

    public function testLaListeMontreLEtatDeServiceDeChaquePage(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $enService = $this->creerPage('agence-durand');
        $suspendue = $this->creerPage('agence-martin');
        $suspendue->suspendre(new \DateTimeImmutable('2026-08-22 10:00:00'));
        $this->em()->flush();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/pages-publiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Pages publiques');
        $tableau = $client->getCrawler()->filter('tbody')->text();
        self::assertStringContainsString($enService->slug(), $tableau);
        self::assertStringContainsString($suspendue->slug(), $tableau);
        self::assertStringContainsString('Suspendue', $tableau);
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $utilisateurs = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $utilisateurs->enregistrer($admin);
        $client->loginUser($admin);
        $this->passerLeSecondFacteur($client, $admin);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function creerPage(string $slug): PagePublique
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), $slug);
        $this->em()->persist($page);
        $this->em()->flush();

        return $page;
    }
}
