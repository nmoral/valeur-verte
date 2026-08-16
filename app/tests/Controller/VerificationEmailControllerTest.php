<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\User;
use App\Security\UserRepository;
use App\Security\VerificationEmail\JetonVerificationEmail;
use App\Tests\Notification\Double\MailerEnMemoire;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Confirmation d'email (#105, ONB-1). Route publique : le lien de l'email est
 * cliqué sans session, la signature du jeton fait toute la sécurité. Idempotent —
 * un second clic reste sans effet. Jeton invalide ou utilisateur inconnu → 404,
 * sans distinguer les deux (pas d'oracle d'existence).
 */
final class VerificationEmailControllerTest extends WebTestCase
{
    private const HOTE = 'http://app.valeurverte.localhost';

    public function testUnLienValideMarqueLEmailVerifieEtConfirme(): void
    {
        $client = static::createClient();
        $id = $this->seedUser('artisan-confirme@example.com');

        $client->request('GET', self::HOTE.'/verification-email?utilisateur='.$id.'&jeton='.$this->jetonPour($id));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Adresse confirmée');
        self::assertTrue($this->users()->find($id)?->emailEstVerifie());
    }

    public function testUnJetonInvalideEstRejete(): void
    {
        $client = static::createClient();
        $id = $this->seedUser('artisan-mauvais-jeton@example.com');

        $client->request('GET', self::HOTE.'/verification-email?utilisateur='.$id.'&jeton=faux');

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->users()->find($id)?->emailEstVerifie());
    }

    public function testUnUtilisateurInconnuEstRejete(): void
    {
        $client = static::createClient();

        $client->request('GET', self::HOTE.'/verification-email?utilisateur=999999&jeton='.$this->jetonPour(999999));

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaConfirmationEstIdempotente(): void
    {
        $client = static::createClient();
        $id = $this->seedUser('artisan-idempotent@example.com');

        $client->request('GET', self::HOTE.'/verification-email?utilisateur='.$id.'&jeton='.$this->jetonPour($id));
        $client->request('GET', self::HOTE.'/verification-email?utilisateur='.$id.'&jeton='.$this->jetonPour($id));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->users()->find($id)?->emailEstVerifie());
    }

    public function testLeBandeauDeRappelSAfficheTantQueLEmailNestPasVerifie(): void
    {
        $client = static::createClient();
        $user = $this->seedUserEntite('artisan-bandeau@example.com', verifie: false);
        $client->loginUser($user);

        $client->request('GET', self::HOTE.'/audit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.rappel-verif-email');
        self::assertSelectorExists('a[href="/verification-email/renvoyer"]');
    }

    public function testAucunBandeauQuandLEmailEstVerifie(): void
    {
        $client = static::createClient();
        $user = $this->seedUserEntite('artisan-verifie@example.com', verifie: true);
        $client->loginUser($user);

        $client->request('GET', self::HOTE.'/audit');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.rappel-verif-email');
    }

    public function testLeRenvoiReexpedieLEmailPourUnCompteConnecteNonVerifie(): void
    {
        $client = static::createClient();
        $user = $this->seedUserEntite('artisan-renvoi@example.com', verifie: false);
        $client->loginUser($user);

        $client->request('GET', self::HOTE.'/verification-email/renvoyer');

        self::assertResponseRedirects();
        $mailer = self::getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $mailer);
        self::assertCount(1, $mailer->envoyes());
        self::assertStringContainsString('/verification-email?utilisateur=', $mailer->envoyes()[0]->corpsHtml());
    }

    private function seedUser(string $email): int
    {
        $id = $this->seedUserEntite($email, verifie: false)->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function seedUserEntite(string $email, bool $verifie): User
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        if ($verifie) {
            $user->marquerEmailVerifie(new \DateTimeImmutable());
        }
        $this->users()->enregistrer($user);

        return $user;
    }

    private function jetonPour(int $userId): string
    {
        $jetons = self::getContainer()->get(JetonVerificationEmail::class);
        self::assertInstanceOf(JetonVerificationEmail::class, $jetons);

        return $jetons->pour($userId);
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
