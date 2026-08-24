<?php

declare(strict_types=1);

namespace App\Tests\Security\ChangementEmail;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditDoctrine;
use App\Security\ChangementEmail\DemandeChangementEmailRepository;
use App\Security\ChangementEmail\EnvoiDemandeChangementEmail;
use App\Security\EmailDejaUtilise;
use App\Security\EmailInvalide;
use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Demande de changement d'adresse email (#310). L'adresse est à la fois
 * l'identifiant de connexion, la cible de la vérification d'email (#105), celle
 * des relances (#108) et celle du mot de passe oublié (#194) : la poser sans
 * preuve, c'est risquer de couper l'accès **et** le seul canal de récupération.
 * D'où deux envois — confirmation sur la nouvelle adresse, avertissement sur
 * l'ancienne — et aucune modification du compte à ce stade.
 */
final class EnvoiDemandeChangementEmailTest extends KernelTestCase
{
    public function testLaNouvelleAdresseRecoitUnLienDeConfirmation(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-demande@example.com');

        $this->envoi()->pour($user, 'nouvelle-demande@example.com');

        $confirmation = $this->emailVers('nouvelle-demande@example.com');
        // Email transactionnel : pas d'adresse de réponse.
        self::assertNull($confirmation->repondreA());
        // Le lien porte un jeton qui désigne une demande réellement stockée, et
        // cette demande vise bien l'adresse à laquelle l'email a été envoyé.
        $demande = $this->demandes()->trouverParEmpreinte($this->extraireEmpreinte($confirmation->corpsHtml()));
        self::assertNotNull($demande);
        self::assertSame('nouvelle-demande@example.com', $demande->nouvelEmail());
    }

    public function testLAncienneAdresseEstAvertieDeLaDemande(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-avert@example.com');

        $this->envoi()->pour($user, 'nouvelle-avert@example.com');

        // Garde-fou contre la prise de contrôle d'un compte dont la session a été
        // volée : le titulaire légitime l'apprend sur la boîte qu'il relève encore.
        $avertissement = $this->emailVers('artisan-avert@example.com');
        // L'avertissement ne porte AUCUN lien de confirmation : le voler ne
        // permettrait pas de valider la bascule.
        self::assertStringNotContainsString('/mon-compte/email/confirmer', $avertissement->corpsHtml());
        // Il nomme en revanche l'adresse visée, sans quoi il est inactionnable.
        self::assertStringContainsString('nouvelle-avert@example.com', $avertissement->corpsHtml());
    }

    public function testLeCompteNestPasModifieTantQueLaDemandeNestPasConfirmee(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-inchange@example.com');

        $this->envoi()->pour($user, 'nouvelle-inchange@example.com');

        self::assertSame('artisan-inchange@example.com', $user->getEmail());
        self::assertNull($this->users()->findOneBy(['email' => 'nouvelle-inchange@example.com']));
    }

    public function testUneAdresseDejaUtiliseeParUnAutreCompteEstRefusee(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-collision@example.com');
        $this->seedUser('occupee@example.com');

        $this->expectException(EmailDejaUtilise::class);

        try {
            $this->envoi()->pour($user, 'occupee@example.com');
        } finally {
            // Rien n'est parti : ni confirmation vers l'adresse d'autrui (qui
            // apprendrait ainsi qu'on la convoite), ni avertissement.
            self::assertCount(0, $this->mailer()->envoyes());
        }
    }

    public function testUneVarianteDUneAdresseDejaUtiliseeEstRefusee(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-variante@example.com');
        $this->seedUser('p.r.o@gmail.com');

        // Même boîte que le compte d'autrui, autre écriture (#331) : le
        // changement d'adresse est la troisième porte d'entrée, elle doit
        // appliquer la même règle d'identité que l'inscription.
        $this->expectException(EmailDejaUtilise::class);

        try {
            $this->envoi()->pour($user, 'P.R.O+Autre@GoogleMail.com');
        } finally {
            self::assertCount(0, $this->mailer()->envoyes());
        }
    }

    public function testSaProprePresenteAdresseEstRefusee(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-meme@example.com');

        $this->expectException(EmailDejaUtilise::class);

        $this->envoi()->pour($user, 'artisan-meme@example.com');
    }

    public function testUneAdresseMalFormeeEstRefusee(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-format@example.com');

        $this->expectException(EmailInvalide::class);

        try {
            $this->envoi()->pour($user, 'pas-une-adresse');
        } finally {
            self::assertCount(0, $this->mailer()->envoyes());
        }
    }

    public function testUneNouvelleDemandeInvalideLaPrecedente(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-rejoue@example.com');

        $this->envoi()->pour($user, 'premiere@example.com');
        $premiere = $this->demandes()->trouverParEmpreinte(
            $this->extraireEmpreinte($this->emailVers('premiere@example.com')->corpsHtml()),
        );
        self::assertNotNull($premiere);
        self::assertTrue($premiere->estConfirmable(new \DateTimeImmutable()));

        $this->envoi()->pour($user, 'seconde@example.com');

        self::assertFalse($premiere->estConfirmable(new \DateTimeImmutable()));
    }

    public function testLaDemandeEstTraceeAuJournalDAudit(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-journal-demande@example.com');

        $this->envoi()->pour($user, 'nouvelle-journal@example.com');

        $actions = array_map(
            static fn ($evenement) => $evenement->action(),
            $this->journal()->evenementsRecents(),
        );
        self::assertContains('compte.demande_changement_email', $actions);
    }

    private function extraireEmpreinte(string $corpsHtml): string
    {
        if (1 !== preg_match('#/mon-compte/email/confirmer\?jeton=([0-9a-f]{64})#', $corpsHtml, $m)) {
            self::fail('Lien de confirmation introuvable dans l\'email.');
        }

        return (new GenerateurJeton())->empreinte($m[1]);
    }

    private function emailVers(string $destinataire): \App\Notification\Email
    {
        foreach ($this->mailer()->envoyes() as $email) {
            if ($destinataire === $email->destinataire()) {
                return $email;
            }
        }

        self::fail(\sprintf('Aucun email envoyé à « %s ».', $destinataire));
    }

    private function seedUser(string $email): User
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $this->users()->enregistrer($user);

        return $user;
    }

    private function envoi(): EnvoiDemandeChangementEmail
    {
        $service = self::getContainer()->get(EnvoiDemandeChangementEmail::class);
        self::assertInstanceOf(EnvoiDemandeChangementEmail::class, $service);

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

    private function demandes(): DemandeChangementEmailRepository
    {
        $repository = self::getContainer()->get(DemandeChangementEmailRepository::class);
        self::assertInstanceOf(DemandeChangementEmailRepository::class, $repository);

        return $repository;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
