<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Rapport\RapportRepository;
use App\Compte\CompteRepository;
use App\Security\CreateurDeCompte;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suspension de compte (#47/#322) : un compte suspendu garde sa session mais ne
 * peut plus produire de rapport — la création d'audit est fermée et renvoyée vers
 * l'espace pro (où le bandeau explique la suspension). Tant que le compte est
 * actif, la saisie reste accessible.
 */
final class AuditBloqueSiCompteSuspenduTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';

    public function testUnCompteSuspenduNePeutPlusProduireDeRapport(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecter($client, 'suspendu-audit@example.com');
        $this->suspendre($tenantId);

        // La saisie (GET) renvoie vers l'espace pro.
        $client->request('GET', 'http://'.self::HOTE_PRO.'/audit');
        self::assertResponseStatusCodeSame(302);
        self::assertTrue(str_ends_with((string) $client->getResponse()->headers->get('Location'), '/'));

        // La création (POST) est bloquée : aucun rapport produit.
        $client->request('POST', 'http://'.self::HOTE_PRO.'/audit', [
            'adresse' => '1 rue Suspendue, Toulouse',
            'surface_batie' => '90',
            'surface_terrain' => '300',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'code_insee' => '31011',
        ]);
        self::assertResponseStatusCodeSame(302);
        self::assertSame(0, $this->rapports()->count(['tenantId' => $tenantId]));
    }

    private function connecter(KernelBrowser $client, string $email): int
    {
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);
        $user = $createur->creer($email, 'motdepasse-solide', '2026-08-16');
        $client->loginUser($user);
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        return $tenantId;
    }

    private function suspendre(int $tenantId): void
    {
        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);
        $compte = $comptes->pourTenant($tenantId);
        self::assertNotNull($compte);
        $compte->suspendre(new \DateTimeImmutable('2026-08-20 10:00:00'));
        $comptes->enregistrer($compte);
    }

    private function rapports(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }
}
