<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Compte\Formule;
use App\Compte\ParametresFormule;
use App\Compte\ReglagesFormuleRepository;
use App\Security\CreateurDeCompte;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fin d'essai (#108) : une fois l'essai terminé (quota consommé OU période
 * écoulée), la création d'un nouvel audit est bloquée et renvoyée vers l'écran de
 * bilan (la bascule). Tant que l'essai court, l'audit reste accessible.
 */
final class AuditBloqueApresEssaiTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';

    public function testUnNouvelAuditEstBloqueQuandLEssaiEstTermine(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecter($client, 'essai-fini@example.com');
        // Quota Classique ramené à 1, puis 1 rapport consommé → essai terminé.
        $this->reglages()->enregistrer(Formule::Classique, new ParametresFormule(60, 1, null));
        $this->seedRapport($tenantId, '2026-Z1');

        // La saisie (GET) renvoie vers le bilan.
        $client->request('GET', 'http://'.self::HOTE_PRO.'/audit');
        self::assertResponseStatusCodeSame(302);
        self::assertTrue(str_ends_with((string) $client->getResponse()->headers->get('Location'), '/compte/bilan'));

        // La création (POST) est bloquée : aucun rapport supplémentaire.
        $client->request('POST', 'http://'.self::HOTE_PRO.'/audit', [
            'adresse' => '1 rue Bloquee, Toulouse',
            'surface_batie' => '90',
            'surface_terrain' => '300',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'code_insee' => '31011',
        ]);
        self::assertResponseStatusCodeSame(302);
        self::assertSame(1, $this->rapports()->count(['tenantId' => $tenantId]));
    }

    public function testTantQueLEssaiCourtLaSaisieResteAccessible(): void
    {
        $client = static::createClient();
        $this->connecter($client, 'essai-en-cours@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/audit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/audit"]');
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

    private function reglages(): ReglagesFormuleRepository
    {
        $repository = self::getContainer()->get(ReglagesFormuleRepository::class);
        self::assertInstanceOf(ReglagesFormuleRepository::class, $repository);

        return $repository;
    }

    private function rapports(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }
}
