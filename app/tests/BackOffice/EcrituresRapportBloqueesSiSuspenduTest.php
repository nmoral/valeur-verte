<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\Compte\CompteRepository;
use App\Security\CreateurDeCompte;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suspension (#322) : au-delà de la production (audit), un compte suspendu ne peut
 * plus **modifier** un rapport — ligne d'ajustement (#30), déclarations (#26), ni
 * signaler une estimation (#49). Les écritures sont refusées ; la lecture reste.
 */
final class EcrituresRapportBloqueesSiSuspenduTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';

    public function testLAjustementEstRefuse(): void
    {
        $client = $this->proSuspendu('ajustement-suspendu@example.com');

        $client->request('POST', 'http://'.self::HOTE_PRO.'/audit/resultat', ['montant' => '1000', 'motif' => 'x']);

        self::assertResponseStatusCodeSame(302);
        self::assertTrue(str_ends_with((string) $client->getResponse()->headers->get('Location'), '/'));
    }

    public function testLesDeclarationsSontRefusees(): void
    {
        $client = $this->proSuspendu('declarations-suspendu@example.com');

        $client->request('POST', 'http://'.self::HOTE_PRO.'/audit/resultat/declarations', ['categorie_revenu' => 'intermediaire']);

        self::assertResponseStatusCodeSame(302);
        self::assertTrue(str_ends_with((string) $client->getResponse()->headers->get('Location'), '/'));
    }

    public function testLeSignalementEstRefuse(): void
    {
        $client = $this->proSuspendu('signalement-suspendu@example.com');

        $client->request('POST', 'http://'.self::HOTE_PRO.'/audit/resultat/signalement', ['message' => 'faux']);

        self::assertResponseStatusCodeSame(403);
    }

    private function proSuspendu(string $email): KernelBrowser
    {
        $client = static::createClient();
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);
        $user = $createur->creer($email, 'motdepasse-solide', '2026-08-16');
        $client->loginUser($user);
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);
        $compte = $comptes->pourTenant($tenantId);
        self::assertNotNull($compte);
        $compte->suspendre(new \DateTimeImmutable('2026-08-20 10:00:00'));
        $comptes->enregistrer($compte);

        return $client;
    }
}
