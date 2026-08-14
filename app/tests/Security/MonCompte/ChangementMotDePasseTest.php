<?php

declare(strict_types=1);

namespace App\Tests\Security\MonCompte;

use App\Security\MonCompte\ChangementMotDePasse;
use App\Security\MonCompte\MotDePasseActuelInvalide;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Changement de mot de passe d'un compte connecté (#206, AUTH-3). On ne change
 * pas à l'aveugle une session ouverte : le mot de passe actuel doit être vérifié
 * avant de poser le nouveau.
 */
final class ChangementMotDePasseTest extends KernelTestCase
{
    private const ACTUEL = 'MotDePasseActuel1';
    private const NOUVEAU = 'MonNouveauMotDePasse2';

    public function testUnMotDePasseActuelValideChangeLeMotDePasse(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-change@example.com', self::ACTUEL);

        $this->changement()->changer($user, self::ACTUEL, self::NOUVEAU);

        $recharge = $this->users()->findOneBy(['email' => 'artisan-change@example.com']);
        self::assertNotNull($recharge);
        self::assertTrue($this->hasher()->isPasswordValid($recharge, self::NOUVEAU));
        self::assertFalse($this->hasher()->isPasswordValid($recharge, self::ACTUEL));
    }

    public function testUnMotDePasseActuelInvalideEstRefuseEtLaisseInchange(): void
    {
        self::bootKernel();
        $user = $this->seedUser('artisan-mauvais@example.com', self::ACTUEL);

        try {
            $this->changement()->changer($user, 'PasLeBonMotDePasse9', self::NOUVEAU);
            self::fail('Un mot de passe actuel invalide aurait dû être refusé.');
        } catch (MotDePasseActuelInvalide) {
            $recharge = $this->users()->findOneBy(['email' => 'artisan-mauvais@example.com']);
            self::assertNotNull($recharge);
            self::assertTrue($this->hasher()->isPasswordValid($recharge, self::ACTUEL));
        }
    }

    private function seedUser(string $email, string $motDePasse): User
    {
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant(4242);
        $user->definirMotDePasse($this->hasher()->hashPassword($user, $motDePasse));
        $this->users()->enregistrer($user);

        return $user;
    }

    private function changement(): ChangementMotDePasse
    {
        $service = self::getContainer()->get(ChangementMotDePasse::class);
        self::assertInstanceOf(ChangementMotDePasse::class, $service);

        return $service;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
