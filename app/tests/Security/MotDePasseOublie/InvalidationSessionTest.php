<?php

declare(strict_types=1);

namespace App\Tests\Security\MotDePasseOublie;

use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\MotDePasseOublie\JetonReinitialisation;
use App\Security\MotDePasseOublie\JetonReinitialisationRepository;
use App\Security\MotDePasseOublie\ReinitialisationMotDePasse;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Invalidation des sessions à la réinitialisation (#199, AUTH-2). Une session
 * ouverte avant la réinitialisation ne doit pas survivre au changement de mot de
 * passe : au rafraîchissement suivant, le token porte l'ancien hash, le firewall
 * détecte le changement et déconnecte. Ce test verrouille ce comportement pour
 * notre firewall `main` (stateful) et l'entité User.
 */
final class InvalidationSessionTest extends WebTestCase
{
    private const HOTE = 'http://app.valeurverte.localhost';

    public function testUneReinitialisationDeconnecteLesSessionsDejaOuvertes(): void
    {
        $client = static::createClient();
        $user = $this->seedUser('artisan-session@example.com', 'MotDePasseUn1');

        $this->seConnecter($client, 'artisan-session@example.com', 'MotDePasseUn1');
        // Session active : l'espace pro est accessible.
        $client->request('GET', self::HOTE.'/');
        self::assertResponseIsSuccessful();

        // Réinitialisation : le hash du compte change en base.
        $this->reinitialiserVers($user, 'MotDePasseDeux2');

        // La session ouverte auparavant n'est plus authentifiée.
        $client->request('GET', self::HOTE.'/');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    private function seConnecter(KernelBrowser $client, string $email, string $motDePasse): void
    {
        $crawler = $client->request('GET', self::HOTE.'/login');
        $formulaire = $crawler->selectButton('Se connecter')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $client->submit($formulaire);
        $client->followRedirect();
    }

    private function reinitialiserVers(User $user, string $nouveauMotDePasse): void
    {
        $generateur = new GenerateurJeton();
        $brut = $generateur->jetonBrut();
        $userId = $user->getId();
        self::assertNotNull($userId);
        $jetons = self::getContainer()->get(JetonReinitialisationRepository::class);
        self::assertInstanceOf(JetonReinitialisationRepository::class, $jetons);
        $jetons->enregistrer(new JetonReinitialisation(
            $userId,
            $generateur->empreinte($brut),
            new \DateTimeImmutable('+1 hour'),
        ));

        $reinitialisation = self::getContainer()->get(ReinitialisationMotDePasse::class);
        self::assertInstanceOf(ReinitialisationMotDePasse::class, $reinitialisation);
        $reinitialisation->reinitialiser($brut, $nouveauMotDePasse);
    }

    private function seedUser(string $email, string $motDePasse): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $user->definirMotDePasse($hasher->hashPassword($user, $motDePasse));
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $repository->enregistrer($user);

        return $user;
    }
}
