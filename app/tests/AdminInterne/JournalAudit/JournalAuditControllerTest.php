<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\JournalAudit;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditDoctrine;
use App\Security\User;
use App\Security\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JournalAuditControllerTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLeJournalExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/journal');

        self::assertResponseRedirects('/login');
    }

    public function testLeJournalListeLesEvenementsDuPlusRecentAuPlusAncien(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->enregistrer($this->evenement('compte.creation', '2026-08-13 09:00:00'));
        $this->enregistrer($this->evenement('coefficient.flag_aberrant', '2026-08-13 11:00:00'));

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/journal');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Journal d'audit");
        // Le plus récent en première ligne, l'ancien ensuite.
        self::assertSelectorTextContains('tbody tr:first-child', 'coefficient.flag_aberrant');
        self::assertSelectorTextContains('tbody tr:nth-child(2)', 'compte.creation');
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function enregistrer(EvenementAudit $evenement): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $journal = $manager->getRepository(EvenementAudit::class);
        self::assertInstanceOf(JournalAuditDoctrine::class, $journal);
        $journal->enregistrer($evenement);
    }

    private function evenement(string $action, string $survenuLe): EvenementAudit
    {
        return new EvenementAudit(
            acteur: 'n.moral@live.fr',
            action: $action,
            cible: 'maille:31555',
            contexte: [],
            survenuLe: new \DateTimeImmutable($survenuLe),
        );
    }
}
