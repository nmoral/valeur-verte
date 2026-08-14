<?php

declare(strict_types=1);

namespace App\Tests\Security\VerificationEmail;

use App\Security\User;
use App\Security\UserRepository;
use App\Security\VerificationEmail\EnvoiVerificationEmail;
use App\Security\VerificationEmail\JetonVerificationEmail;
use App\Tests\Notification\Double\MailerEnMemoire;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Envoi de l'email de vérification (#105, ONB-1) : email transactionnel portant un
 * lien signé de confirmation. Aucun envoi réel sous test (Mailer en mémoire).
 */
final class EnvoiVerificationEmailTest extends KernelTestCase
{
    public function testIlEnvoieUnEmailAvecUnLienDeVerificationSigne(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-verif@example.com');

        $this->envoi()->envoyerPour($user);

        $emails = $this->mailer()->envoyes();
        self::assertCount(1, $emails);
        self::assertSame('artisan-verif@example.com', $emails[0]->destinataire());
        // Email transactionnel : pas d'adresse de réponse.
        self::assertNull($emails[0]->repondreA());

        $userId = $user->getId();
        self::assertNotNull($userId);
        if (1 !== preg_match('#/verification-email\?utilisateur='.$userId.'&jeton=([0-9a-f]{64})#', $emails[0]->corpsHtml(), $m)) {
            self::fail('Lien de vérification introuvable dans l\'email.');
        }
        self::assertTrue($this->jetons()->estValide($userId, $m[1]));
    }

    private function seedUser(string $email): User
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $repository->enregistrer($user);

        return $user;
    }

    private function envoi(): EnvoiVerificationEmail
    {
        $service = self::getContainer()->get(EnvoiVerificationEmail::class);
        self::assertInstanceOf(EnvoiVerificationEmail::class, $service);

        return $service;
    }

    private function mailer(): MailerEnMemoire
    {
        $mailer = self::getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $mailer);

        return $mailer;
    }

    private function jetons(): JetonVerificationEmail
    {
        $jetons = self::getContainer()->get(JetonVerificationEmail::class);
        self::assertInstanceOf(JetonVerificationEmail::class, $jetons);

        return $jetons;
    }
}
