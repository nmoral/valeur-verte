<?php

declare(strict_types=1);

namespace App\Tests\Controller\Compte;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Export self-service (#309, RGPD portabilité) : depuis « Mon compte », un pro
 * télécharge toutes ses données en JSON, protégé par son mot de passe, et l'acte
 * est tracé au journal.
 */
final class ExportCompteControllerTest extends WebTestCase
{
    private const APP = 'http://app.valeurverte.localhost';
    private const MOT_DE_PASSE = 'MotDePasseActuel1';

    public function testLaPageAfficheLaConfirmationParMotDePasse(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-export@example.com', 8001);

        $client->request('GET', self::APP.'/mon-compte/export');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="motdepasse"]');
    }

    public function testLaPageMonCompteProposeLExportAuPro(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-lien-export@example.com', 8002);

        $client->request('GET', self::APP.'/mon-compte');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mon-compte/export"]');
    }

    public function testUnMotDePasseValideTelechargeLeJsonEtTraceLActe(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-dl@example.com', 8003);

        $this->demander($client, self::MOT_DE_PASSE);

        self::assertResponseIsSuccessful();
        $reponse = $client->getResponse();
        self::assertStringContainsString('application/json', (string) $reponse->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        self::assertStringContainsString('.json', (string) $reponse->headers->get('Content-Disposition'));

        $document = json_decode((string) $reponse->getContent(), true);
        self::assertIsArray($document);
        self::assertSame(8003, $document['tenant_id']);
        self::assertStringContainsString('pro-dl@example.com', (string) $reponse->getContent());

        $evenements = $this->journal()->evenementsParPrefixe('rgpd.export_genere');
        self::assertNotEmpty($evenements);
        self::assertSame('compte#8003', $evenements[0]->cible());
        self::assertSame('pro-dl@example.com', $evenements[0]->acteur());
    }

    public function testUnMotDePasseIncorrectNeTelechargeRien(): void
    {
        $client = static::createClient();
        $this->seedProAvecCompte($client, 'pro-dl-ko@example.com', 8004);

        $this->demander($client, 'PasLeBon9');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="motdepasse"]');
        self::assertNull($client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testLaPageExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', self::APP.'/mon-compte/export');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    private function demander(KernelBrowser $client, string $motDePasse): void
    {
        $crawler = $client->request('GET', self::APP.'/mon-compte/export');
        $formulaire = $crawler->selectButton('Télécharger mes données')->form();
        $formulaire['motdepasse'] = $motDePasse;
        $client->submit($formulaire);
    }

    private function seedProAvecCompte(KernelBrowser $client, string $email, int $tenantId): void
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant($tenantId);
        $user->definirMotDePasse($this->hasher()->hashPassword($user, self::MOT_DE_PASSE));
        $this->users()->enregistrer($user);
        $this->comptes()->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable('2026-01-01')));
        $client->loginUser($user);
    }

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }

    private function journal(): JournalAudit
    {
        $journal = self::getContainer()->get(JournalAudit::class);
        self::assertInstanceOf(JournalAudit::class, $journal);

        return $journal;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
