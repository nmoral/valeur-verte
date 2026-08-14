<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Rapport\ArchiveurRapport;
use App\BackOffice\Rapport\Dpe;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantContext;
use App\Tests\Notification\Double\MailerEnMemoire;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Déclencheur ONB-7 (#109) : archiver un rapport est l'**événement** qui lance la
 * relance conversationnelle — pas un calendrier. Elle part une seule fois, au
 * premier rapport du compte, jamais aux suivants.
 */
final class ArchiveurRapportTest extends KernelTestCase
{
    public function testArchiverLePremierRapportDeclencheLaRelanceUneSeuleFois(): void
    {
        self::bootKernel();
        $tenant = 8811;
        $this->seedCompte($tenant, 'artisan-archive@example.com');
        $this->contexteTenant()->definir($tenant);

        $this->archiveur()->archiver($this->saisie(), null, null);

        $emails = $this->mailer()->envoyes();
        self::assertCount(1, $emails);
        self::assertSame('artisan-archive@example.com', $emails[0]->destinataire());
        self::assertStringContainsString('premier rapport', $emails[0]->corpsHtml());

        // Un second rapport n'entraîne pas de nouvel email (idempotence).
        $this->archiveur()->archiver($this->saisie(), null, null);
        self::assertCount(1, $this->mailer()->envoyes());
    }

    private function saisie(): SaisieAudit
    {
        return SaisieAudit::depuis('12 rue des Lilas, 31000 Toulouse', 90, 300, Dpe::E, Dpe::C);
    }

    private function seedCompte(int $tenantId, string $email): void
    {
        $artisan = new User($email, ['ROLE_PRO']);
        $artisan->rattacherAuTenant($tenantId);
        $this->users()->enregistrer($artisan);
        $this->comptes()->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable()));
    }

    private function archiveur(): ArchiveurRapport
    {
        $archiveur = self::getContainer()->get(ArchiveurRapport::class);
        self::assertInstanceOf(ArchiveurRapport::class, $archiveur);

        return $archiveur;
    }

    private function contexteTenant(): TenantContext
    {
        $contexte = self::getContainer()->get(TenantContext::class);
        self::assertInstanceOf(TenantContext::class, $contexte);

        return $contexte;
    }

    private function mailer(): MailerEnMemoire
    {
        $mailer = self::getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $mailer);

        return $mailer;
    }

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
