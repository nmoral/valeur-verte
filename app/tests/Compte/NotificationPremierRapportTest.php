<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\JetonDesabonnement;
use App\Compte\NotificationPremierRapport;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use App\Tests\Notification\Double\MailerQuiEchoue;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Relance conversationnelle après le premier rapport (#109, ONB-7) : un email
 * personnel, déclenché sur événement (génération d'un rapport), envoyé une seule
 * fois par compte, avec une adresse de réponse réelle. Aucun envoi réel sous test.
 */
final class NotificationPremierRapportTest extends KernelTestCase
{
    public function testElleEnvoieUnEmailPersonnelAuPremierRapportEtUneSeuleFois(): void
    {
        self::bootKernel();
        $this->seedCompte(8801, 'artisan-premier@example.com');

        $this->notification()->surTenant(8801);

        $emails = $this->mailer()->envoyes();
        self::assertCount(1, $emails);
        self::assertSame('artisan-premier@example.com', $emails[0]->destinataire());
        // Réponse dans une vraie boîte (Reply-To) et ton personnel.
        self::assertNotNull($emails[0]->repondreA());
        self::assertStringContainsString('premier rapport', $emails[0]->corpsHtml());
        // Lien de désinscription (email non transactionnel).
        self::assertStringContainsString('/desabonnement?tenant=8801', $emails[0]->corpsHtml());
        self::assertTrue($this->comptes()->pourTenant(8801)?->emailPremierRapportEnvoye());

        // Idempotence : un second événement ne renvoie rien.
        $this->notification()->surTenant(8801);
        self::assertCount(1, $this->mailer()->envoyes());
    }

    public function testElleNEnvoieRienSiLeCompteEstDesabonne(): void
    {
        self::bootKernel();
        $this->seedCompte(8802, 'artisan-desabonne@example.com', desabonne: true);

        $this->notification()->surTenant(8802);

        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testElleNEnvoieRienSansEmailPourLeTenant(): void
    {
        self::bootKernel();
        // Compte sans utilisateur rattaché : aucune adresse où écrire.
        $this->comptes()->enregistrer(Compte::creer(8803, new \DateTimeImmutable()));

        $this->notification()->surTenant(8803);

        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testElleNEnvoieRienPourUnCompteInexistant(): void
    {
        self::bootKernel();

        $this->notification()->surTenant(9999);

        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testUnEchecDEnvoiNeMarquePasLeCompteEtNInterromptPas(): void
    {
        self::bootKernel();
        $this->seedCompte(8804, 'artisan-echec@example.com');

        // Envoi best-effort : même si le fournisseur échoue, aucune exception ne
        // remonte et le compte reste non marqué (l'envoi sera retenté plus tard).
        $this->notificationAvecMailerDefaillant()->surTenant(8804);

        self::assertFalse($this->comptes()->pourTenant(8804)?->emailPremierRapportEnvoye());
    }

    private function notificationAvecMailerDefaillant(): NotificationPremierRapport
    {
        return new NotificationPremierRapport(
            $this->comptes(),
            self::getContainer()->get(Connection::class),
            new MailerQuiEchoue(),
            self::getContainer()->get(Environment::class),
            self::getContainer()->get(ClockInterface::class),
            self::getContainer()->get(JetonDesabonnement::class),
            'valeurverte.localhost',
            'no-reply@valeur-verte.fr',
        );
    }

    private function seedCompte(int $tenantId, string $email, bool $desabonne = false): void
    {
        $artisan = new User($email, ['ROLE_PRO']);
        $artisan->rattacherAuTenant($tenantId);
        $this->users()->enregistrer($artisan);
        $compte = Compte::creer($tenantId, new \DateTimeImmutable());
        if ($desabonne) {
            $compte->seDesabonnerDesEmails(new \DateTimeImmutable());
        }
        $this->comptes()->enregistrer($compte);
    }

    private function notification(): NotificationPremierRapport
    {
        $service = self::getContainer()->get(NotificationPremierRapport::class);
        self::assertInstanceOf(NotificationPremierRapport::class, $service);

        return $service;
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
