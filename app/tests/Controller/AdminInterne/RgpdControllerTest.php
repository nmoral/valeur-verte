<?php

declare(strict_types=1);

namespace App\Tests\Controller\AdminInterne;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran de suivi des demandes RGPD (#309) : depuis l'admin, générer l'export d'un
 * compte pour une demande reçue par email, et consigner l'arrivée puis la réponse
 * — le tout adossé au journal d'audit, qui porte la preuve des délais.
 */
final class RgpdControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLEcranExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rgpd');

        self::assertResponseRedirects('/login');
    }

    public function testLEcranAfficheLesTroisFormulaires(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rgpd');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Demandes RGPD');
        self::assertSelectorExists('form[action="/rgpd/export"]');
        self::assertSelectorExists('form[action="/rgpd/demande"]');
        self::assertSelectorExists('form[action="/rgpd/reponse"]');
    }

    public function testGenererLExportDunCompteParEmailTelechargeLeJsonEtTrace(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seedPro('pro-cible@example.com', 9001);

        $crawler = $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rgpd');
        $client->submit($crawler->selectButton('Télécharger l\'export JSON')->form(['email' => 'pro-cible@example.com']));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $document = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($document);
        self::assertSame(9001, $document['tenant_id']);

        $evenements = $this->journal()->evenementsParPrefixe('rgpd.export_genere');
        self::assertNotEmpty($evenements);
        self::assertSame('compte#9001', $evenements[0]->cible());
        self::assertSame('admin@example.com', $evenements[0]->acteur());
    }

    public function testGenererLExportSurUneAdresseInconnueNeTelechargeRien(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $crawler = $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rgpd');
        $client->submit($crawler->selectButton('Télécharger l\'export JSON')->form(['email' => 'inconnu@example.com']));

        self::assertResponseRedirects('/rgpd');
        self::assertNull($client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testConsignerUneDemandeRecueEcritLEcheanceAuJournal(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seedPro('pro-demande@example.com', 9002);

        $crawler = $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rgpd');
        $client->submit($crawler->selectButton('Consigner la demande')->form([
            'email' => 'pro-demande@example.com',
            'droit' => 'portabilite',
            'recue_le' => '2026-08-20',
        ]));

        self::assertResponseRedirects('/rgpd');
        $evenements = $this->journal()->evenementsParPrefixe('rgpd.demande_recue');
        self::assertNotEmpty($evenements);
        $contexte = $evenements[0]->contexte();
        self::assertSame('compte#9002', $evenements[0]->cible());
        self::assertSame('portabilite', $contexte['droit']);
        self::assertSame('2026-09-20T00:00:00+00:00', $contexte['echeance_le']);
    }

    public function testConsignerUneReponseEcritLaTraceAuJournal(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->seedPro('pro-reponse@example.com', 9003);

        $crawler = $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rgpd');
        $client->submit($crawler->selectButton('Consigner la réponse')->form([
            'email' => 'pro-reponse@example.com',
            'droit' => 'acces',
        ]));

        self::assertResponseRedirects('/rgpd');
        $evenements = $this->journal()->evenementsParPrefixe('rgpd.demande_traitee');
        self::assertNotEmpty($evenements);
        self::assertSame('compte#9003', $evenements[0]->cible());
        self::assertSame('acces', $evenements[0]->contexte()['droit']);
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $this->users()->enregistrer($admin);
        $client->loginUser($admin);
        $this->passerLeSecondFacteur($client, $admin);
    }

    private function seedPro(string $email, int $tenantId): void
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant($tenantId);
        $this->users()->enregistrer($user);
        $this->comptes()->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable('2026-01-01')));
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

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
