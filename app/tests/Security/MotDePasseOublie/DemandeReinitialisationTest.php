<?php

declare(strict_types=1);

namespace App\Tests\Security\MotDePasseOublie;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditDoctrine;
use App\Security\MotDePasseOublie\DemandeReinitialisation;
use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\MotDePasseOublie\JetonReinitialisationRepository;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Demande de réinitialisation (#194, AUTH-1) : un compte existant reçoit un email
 * avec un lien à usage unique ; un compte inconnu ne déclenche rien (réponse
 * neutre, anti-énumération). Une nouvelle demande invalide le jeton précédent.
 * Aucun envoi réel sous test (Mailer en mémoire).
 */
final class DemandeReinitialisationTest extends KernelTestCase
{
    public function testUnCompteExistantRecoitUnEmailAvecUnLienDeReinitialisation(): void
    {
        self::bootKernel();
        $this->seedUser('artisan-oubli@example.com');

        $this->demande()->pour('artisan-oubli@example.com');

        $emails = $this->mailer()->envoyes();
        self::assertCount(1, $emails);
        self::assertSame('artisan-oubli@example.com', $emails[0]->destinataire());
        // Email transactionnel : pas d'adresse de réponse (contrairement à la relance).
        self::assertNull($emails[0]->repondreA());
        // Le corps porte un lien de réinitialisation dont le jeton correspond à un
        // jeton réellement stocké (retrouvé par son empreinte).
        $empreinte = $this->extraireEmpreinte($emails[0]->corpsHtml());
        self::assertNotNull($this->jetons()->trouverParEmpreinte($empreinte));
    }

    public function testUnCompteInconnuNeDeclencheAucunEmail(): void
    {
        self::bootKernel();

        $this->demande()->pour('inconnu@example.com');

        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testUneDemandePourUnCompteExistantEstTraceeAuJournalDAudit(): void
    {
        self::bootKernel();
        $this->seedUser('artisan-journal@example.com');

        $this->demande()->pour('artisan-journal@example.com');

        $actions = array_map(
            static fn ($evenement) => $evenement->action(),
            $this->journal()->evenementsRecents(),
        );
        self::assertContains('mot_de_passe.demande_reinitialisation', $actions);
    }

    public function testUneNouvelleDemandeInvalideLeJetonPrecedent(): void
    {
        self::bootKernel();
        $this->seedUser('artisan-rejoue@example.com');

        $this->demande()->pour('artisan-rejoue@example.com');
        $ancien = $this->jetons()->trouverParEmpreinte(
            $this->extraireEmpreinte($this->mailer()->envoyes()[0]->corpsHtml()),
        );
        self::assertNotNull($ancien);
        self::assertTrue($ancien->estUtilisable(new \DateTimeImmutable()));

        $this->demande()->pour('artisan-rejoue@example.com');

        self::assertFalse($ancien->estUtilisable(new \DateTimeImmutable()));
    }

    private function extraireEmpreinte(string $corpsHtml): string
    {
        if (1 !== preg_match('#/mot-de-passe-oublie/reinitialiser\?jeton=([0-9a-f]{64})#', $corpsHtml, $m)) {
            self::fail('Lien de réinitialisation introuvable dans l\'email.');
        }

        return (new GenerateurJeton())->empreinte($m[1]);
    }

    private function seedUser(string $email): User
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $this->users()->enregistrer($user);

        return $user;
    }

    private function demande(): DemandeReinitialisation
    {
        $service = self::getContainer()->get(DemandeReinitialisation::class);
        self::assertInstanceOf(DemandeReinitialisation::class, $service);

        return $service;
    }

    private function mailer(): MailerEnMemoire
    {
        $mailer = self::getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $mailer);

        return $mailer;
    }

    private function journal(): JournalAuditDoctrine
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $journal = $manager->getRepository(EvenementAudit::class);
        self::assertInstanceOf(JournalAuditDoctrine::class, $journal);

        return $journal;
    }

    private function jetons(): JetonReinitialisationRepository
    {
        $repository = self::getContainer()->get(JetonReinitialisationRepository::class);
        self::assertInstanceOf(JetonReinitialisationRepository::class, $repository);

        return $repository;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
