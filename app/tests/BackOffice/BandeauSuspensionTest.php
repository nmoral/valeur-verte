<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\Compte\CompteRepository;
use App\Security\CreateurDeCompte;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suspension (#322) : l'accès en lecture étant conservé, l'espace pro d'un compte
 * suspendu porte un bandeau qui explique pourquoi les actions sont fermées. Un
 * compte actif ne le voit jamais.
 */
final class BandeauSuspensionTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';

    public function testUnCompteSuspenduVoitLeBandeauSurSonEspace(): void
    {
        $client = $this->connecter('bandeau-suspendu@example.com', suspendu: true);

        $client->request('GET', 'http://'.self::HOTE_PRO.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.compte-suspendu-bandeau');
    }

    public function testUnCompteActifNeVoitPasLeBandeau(): void
    {
        $client = $this->connecter('bandeau-actif@example.com', suspendu: false);

        $client->request('GET', 'http://'.self::HOTE_PRO.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.compte-suspendu-bandeau');
    }

    private function connecter(string $email, bool $suspendu): KernelBrowser
    {
        $client = static::createClient();
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);
        $user = $createur->creer($email, 'motdepasse-solide', '2026-08-16');
        $client->loginUser($user);
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        if ($suspendu) {
            $comptes = self::getContainer()->get(CompteRepository::class);
            self::assertInstanceOf(CompteRepository::class, $comptes);
            $compte = $comptes->pourTenant($tenantId);
            self::assertNotNull($compte);
            $compte->suspendre(new \DateTimeImmutable('2026-08-20 10:00:00'));
            $comptes->enregistrer($compte);
        }

        return $client;
    }
}
