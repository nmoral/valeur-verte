<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Security\CreateurDeCompte;
use App\Security\UserRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Mur de vérification (#333) : sans adresse vérifiée, un pro produit son premier
 * rapport et rien de plus. Le premier reste libre (ONB-4) ; au-delà, l'écran
 * demande la vérification avec un renvoi d'email en un clic. Une adresse vérifiée
 * ne rencontre jamais le mur.
 */
final class AuditMurVerificationTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';

    public function testLePremierRapportResteLibreSansVerification(): void
    {
        $client = static::createClient();
        // Compte neuf non vérifié, aucun rapport : la saisie reste ouverte (ONB-4).
        $this->connecter($client, 'premier-libre@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/audit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/audit"]');
    }

    public function testAuDelaDuPremierRapportSansVerificationLeMurBloque(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecter($client, 'mur-bloque@example.com');
        $this->seedRapport($tenantId, '2026-M1');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/audit');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/audit"]', 'le formulaire d\'audit est fermé');
        self::assertSelectorExists('a[href="/verification-email/renvoyer"]', 'un renvoi d\'email en un clic est proposé');

        // La création (POST) est bloquée : aucun rapport supplémentaire.
        $client->request('POST', 'http://'.self::HOTE_PRO.'/audit', [
            'adresse' => '1 rue Bloquee, Toulouse',
            'surface_batie' => '90',
            'surface_terrain' => '300',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'code_insee' => '31011',
        ]);
        self::assertSame(1, $this->rapports()->count(['tenantId' => $tenantId]));
    }

    public function testUneAdresseVerifieePeutProduireAuDelaDuPremierRapport(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterVerifie($client, 'verifie-ok@example.com');
        $this->seedRapport($tenantId, '2026-V1');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/audit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/audit"]', 'une adresse vérifiée ne rencontre pas le mur');
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

    private function connecterVerifie(KernelBrowser $client, string $email): int
    {
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);
        $user = $createur->creer($email, 'motdepasse-solide', '2026-08-16');
        $user->marquerEmailVerifie(new \DateTimeImmutable('2026-08-24 09:00:00'));
        $this->users()->enregistrer($user);
        $client->loginUser($user);
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        return $tenantId;
    }

    private function seedRapport(int $tenantId, string $reference): void
    {
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$reference.'.pdf',
        );
        $rapport->setTenantId($tenantId);
        $this->rapports()->enregistrer($rapport);
    }

    private function rapports(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
