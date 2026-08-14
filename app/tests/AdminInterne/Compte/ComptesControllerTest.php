<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ComptesControllerTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaPageComptesExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/comptes');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageComptesListeIdentiteCompteurEtStatut(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $tenantId = $this->tenants()->nouveau()->id();
        self::assertNotNull($tenantId);
        $this->seedEntreprise('SARL Toiture Occitane', $tenantId);
        $this->seedRapport('2026-A1', $tenantId);
        $this->seedRapport('2026-A2', $tenantId);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/comptes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Comptes');
        self::assertSelectorTextContains('tbody', 'SARL Toiture Occitane');
        self::assertSelectorTextContains('tbody tr:first-child', '2');
    }

    public function testLaNavigationAdminRelieLesPagesEtMarqueLaCourante(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/comptes');

        self::assertResponseIsSuccessful();
        // La nav latérale partagée relie les autres pages admin…
        self::assertSelectorExists('nav.side a[href="/journal"]');
        self::assertSelectorExists('nav.side a[href="/configuration"]');
        // … et marque la page courante comme active.
        self::assertSelectorTextContains('nav.side a.on', 'Comptes pro');
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function seedEntreprise(string $raisonSociale, int $tenantId): void
    {
        $repository = self::getContainer()->get(EntrepriseRepository::class);
        self::assertInstanceOf(EntrepriseRepository::class, $repository);
        $entreprise = Entreprise::depuisFiche(FicheEntreprise::creer(
            Siret::depuis('12345678901234'),
            $raisonSociale,
            '12 rue des Lilas, 31000 Toulouse',
            '43.29A',
            '6 à 9 salariés',
            StatutRge::Rge,
            ['Isolation des murs'],
        ));
        $entreprise->setTenantId($tenantId);
        $repository->enregistrer($entreprise);
    }

    private function seedRapport(string $reference, int $tenantId): void
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$reference.'.pdf',
        );
        $rapport->setTenantId($tenantId);
        $repository->enregistrer($rapport);
    }

    private function tenants(): TenantRepository
    {
        $repository = self::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $repository);

        return $repository;
    }
}
