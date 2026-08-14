<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Security\CreateurDeCompte;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suivi d'essai côté artisan (#108) : bandeau permanent dans le shell et écran de
 * bilan d'usage. L'artisan ne peut jamais être surpris par la fin de l'essai.
 */
final class EssaiArtisanTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';

    public function testLeShellPorteLeBandeauDEssai(): void
    {
        $client = static::createClient();
        $this->connecterArtisan($client, 'artisan-essai@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.essai-bandeau');
        self::assertSelectorTextContains('.essai-bandeau', 'Essai');
    }

    public function testLeBilanAfficheRapportsGeneresEtContactsCaptures(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-bilan@example.com');
        $this->seedRapport($tenantId, '2026-C1', avecContact: true);
        $this->seedRapport($tenantId, '2026-C2', avecContact: false);

        $client->request('GET', 'http://'.self::HOTE_PRO.'/compte/bilan');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'bilan');
        $texte = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('2', $texte); // rapports générés
        self::assertStringContainsString('1', $texte); // contacts capturés
    }

    private function connecterArtisan(KernelBrowser $client, string $email): int
    {
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);
        $user = $createur->creer($email, 'motdepasse-solide');
        $client->loginUser($user);
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        return $tenantId;
    }

    private function seedRapport(int $tenantId, string $reference, bool $avecContact): void
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $contact = $avecContact ? Contact::creer('Mme Client', 'client@example.com', null) : null;
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$reference.'.pdf',
            $contact,
        );
        $rapport->setTenantId($tenantId);
        $repository->enregistrer($rapport);
    }
}
