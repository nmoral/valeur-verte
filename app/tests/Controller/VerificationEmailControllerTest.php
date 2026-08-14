<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\User;
use App\Security\UserRepository;
use App\Security\VerificationEmail\JetonVerificationEmail;
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

    private function seedUser(string $email): int
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $this->users()->enregistrer($user);
        $id = $user->getId();
        self::assertNotNull($id);

        return $id;
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
