<?php

declare(strict_types=1);

namespace App\Tests\Security\ChangementEmail;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditDoctrine;
use App\Security\ChangementEmail\ChangementEmailInvalide;
use App\Security\ChangementEmail\ConfirmationChangementEmail;
use App\Security\ChangementEmail\DemandeChangementEmail;
use App\Security\ChangementEmail\DemandeChangementEmailRepository;
use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\User;
use App\Security\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Confirmation du changement d'adresse (#310). C'est ici, et seulement ici, que
 * l'adresse du compte bascule : le clic sur le lien reçu à la nouvelle adresse
 * prouve que la boîte est relevée. Tout jeton refusé lève
 * {@see ChangementEmailInvalide}, sans jamais distinguer inconnu / expiré / déjà
 * confirmé — aucun oracle sur l'existence d'une demande.
 */
final class ConfirmationChangementEmailTest extends KernelTestCase
{
    public function testUnJetonValideBasculeLAdresseDuCompte(): void
    {
        self::bootKernel();
        $user = $this->seedUser('avant-bascule@example.com');
        $jetonBrut = $this->seedDemande($user, 'apres-bascule@example.com');

        $this->confirmation()->confirmer($jetonBrut);

        $recharge = $this->users()->find($user->getId());
        self::assertNotNull($recharge);
        self::assertSame('apres-bascule@example.com', $recharge->getEmail());
    }

    public function testLAdresseConfirmeeEstDuMemeCoupReputeeVerifiee(): void
    {
        self::bootKernel();
        $user = $this->seedUser('avant-verif@example.com');
        $jetonBrut = $this->seedDemande($user, 'apres-verif@example.com');
        self::assertFalse($user->emailEstVerifie());

        $this->confirmation()->confirmer($jetonBrut);

        // Le clic est exactement la preuve que réclame la vérification d'email
        // (#105) : redemander une confirmation de l'adresse qu'on vient de
        // confirmer serait absurde.
        $recharge = $this->users()->find($user->getId());
        self::assertNotNull($recharge);
        self::assertTrue($recharge->emailEstVerifie());
    }

    public function testUnJetonNeSertQuUneFois(): void
    {
        self::bootKernel();
        $user = $this->seedUser('avant-unique@example.com');
        $jetonBrut = $this->seedDemande($user, 'apres-unique@example.com');
        $this->confirmation()->confirmer($jetonBrut);

        $this->expectException(ChangementEmailInvalide::class);

        $this->confirmation()->confirmer($jetonBrut);
    }

    public function testUnJetonExpireEstRefuse(): void
    {
        self::bootKernel();
        $user = $this->seedUser('avant-expire@example.com');
        $jetonBrut = $this->seedDemande($user, 'apres-expire@example.com', new \DateTimeImmutable('2020-01-01 00:00:00'));

        try {
            $this->confirmation()->confirmer($jetonBrut);
            self::fail('Un jeton expiré doit être refusé.');
        } catch (ChangementEmailInvalide) {
            $recharge = $this->users()->find($user->getId());
            self::assertNotNull($recharge);
            self::assertSame('avant-expire@example.com', $recharge->getEmail());
        }
    }

    public function testUnJetonInconnuEstRefuse(): void
    {
        self::bootKernel();

        $this->expectException(ChangementEmailInvalide::class);

        $this->confirmation()->confirmer(str_repeat('a', 64));
    }

    public function testUneAdresseDevenueIndisponibleEntreTempsEstRefusee(): void
    {
        self::bootKernel();
        $user = $this->seedUser('avant-course@example.com');
        $jetonBrut = $this->seedDemande($user, 'convoitee@example.com');
        // Quelqu'un s'inscrit avec l'adresse visée entre la demande et le clic :
        // la contrainte d'unicité la refuserait par une erreur 500 au visage du
        // pro. On la refuse proprement, et le compte garde son adresse.
        $this->seedUser('convoitee@example.com');

        try {
            $this->confirmation()->confirmer($jetonBrut);
            self::fail('Une adresse prise entre-temps doit être refusée.');
        } catch (ChangementEmailInvalide) {
            $recharge = $this->users()->find($user->getId());
            self::assertNotNull($recharge);
            self::assertSame('avant-course@example.com', $recharge->getEmail());
        }
    }

    public function testLaConfirmationEstTraceeAuJournalDAudit(): void
    {
        self::bootKernel();
        $user = $this->seedUser('avant-journal@example.com');
        $jetonBrut = $this->seedDemande($user, 'apres-journal@example.com');

        $this->confirmation()->confirmer($jetonBrut);

        $actions = array_map(
            static fn ($evenement) => $evenement->action(),
            $this->journal()->evenementsRecents(),
        );
        self::assertContains('compte.changement_email_confirme', $actions);
    }

    private function seedUser(string $email): User
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $this->users()->enregistrer($user);

        return $user;
    }

    /**
     * Pose une demande directement en base et rend le jeton brut correspondant :
     * ces tests portent sur la confirmation, pas sur l'émission.
     */
    private function seedDemande(User $user, string $nouvelEmail, ?\DateTimeImmutable $expireLe = null): string
    {
        $userId = $user->getId();
        self::assertNotNull($userId);

        $generateur = new GenerateurJeton();
        $jetonBrut = $generateur->jetonBrut();
        $this->demandes()->enregistrer(new DemandeChangementEmail(
            $userId,
            $nouvelEmail,
            $generateur->empreinte($jetonBrut),
            $expireLe ?? new \DateTimeImmutable('+1 hour'),
        ));

        return $jetonBrut;
    }

    private function confirmation(): ConfirmationChangementEmail
    {
        $service = self::getContainer()->get(ConfirmationChangementEmail::class);
        self::assertInstanceOf(ConfirmationChangementEmail::class, $service);

        return $service;
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
