<?php

declare(strict_types=1);

namespace App\Tests\Security\MotDePasseOublie;

use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\MotDePasseOublie\JetonInvalide;
use App\Security\MotDePasseOublie\JetonReinitialisation;
use App\Security\MotDePasseOublie\JetonReinitialisationRepository;
use App\Security\MotDePasseOublie\ReinitialisationMotDePasse;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Réinitialisation effective (#194, AUTH-1) : un jeton valide change le mot de
 * passe et se consomme (usage unique) ; un jeton expiré, déjà consommé ou inconnu
 * est refusé et laisse le mot de passe inchangé.
 */
final class ReinitialisationMotDePasseTest extends KernelTestCase
{
    private const MDP_INITIAL = 'AncienMotDePasse1';
    private const MDP_NOUVEAU = 'NouveauMotDePasse1';

    public function testUnJetonValideChangeLeMotDePasseEtSeConsomme(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-reset@example.com');
        $brut = $this->emettreJetonPour($user, new \DateTimeImmutable('+1 hour'));

        $this->reinitialisation()->reinitialiser($brut, self::MDP_NOUVEAU);

        $recharge = $this->users()->findOneBy(['email' => 'artisan-reset@example.com']);
        self::assertNotNull($recharge);
        self::assertTrue($this->hasher()->isPasswordValid($recharge, self::MDP_NOUVEAU));
        // Usage unique : rejouer le même jeton échoue désormais.
        $this->expectException(JetonInvalide::class);
        $this->reinitialisation()->reinitialiser($brut, 'EncoreUnAutre1');
    }

    public function testUnJetonExpireEstRefuseEtLaisseLeMotDePasseInchange(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-expire@example.com');
        $brut = $this->emettreJetonPour($user, new \DateTimeImmutable('-1 minute'));

        try {
            $this->reinitialisation()->reinitialiser($brut, self::MDP_NOUVEAU);
            self::fail('Un jeton expiré aurait dû être refusé.');
        } catch (JetonInvalide) {
            $recharge = $this->users()->findOneBy(['email' => 'artisan-expire@example.com']);
            self::assertNotNull($recharge);
            self::assertTrue($this->hasher()->isPasswordValid($recharge, self::MDP_INITIAL));
        }
    }

    public function testUnJetonInconnuEstRefuse(): void
    {
        self::bootKernel();
        $this->seedUser('artisan-inconnu@example.com');

        $this->expectException(JetonInvalide::class);
        $this->reinitialisation()->reinitialiser((new GenerateurJeton())->jetonBrut(), self::MDP_NOUVEAU);
    }

    private function emettreJetonPour(User $user, \DateTimeImmutable $expireLe): string
    {
        $generateur = new GenerateurJeton();
        $brut = $generateur->jetonBrut();
        $userId = $user->getId();
        self::assertNotNull($userId);
        $this->jetons()->enregistrer(new JetonReinitialisation($userId, $generateur->empreinte($brut), $expireLe));

        return $brut;
    }

    private function seedUser(string $email): User
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $user->definirMotDePasse($this->hasher()->hashPassword($user, self::MDP_INITIAL));
        $this->users()->enregistrer($user);

        return $user;
    }

    private function reinitialisation(): ReinitialisationMotDePasse
    {
        $service = self::getContainer()->get(ReinitialisationMotDePasse::class);
        self::assertInstanceOf(ReinitialisationMotDePasse::class, $service);

        return $service;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
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
