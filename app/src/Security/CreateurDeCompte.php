<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création d'un compte pro en self-service (ONB-1) : compte actif immédiatement,
 * sans validation manuelle. L'enrichissement par le SIRET (ONB-2, #106) et le
 * rate limiting (#44) viendront s'ajouter ici et en amont.
 */
final class CreateurDeCompte
{
    public function __construct(
        private readonly UserRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @throws EmailDejaUtilise
     */
    public function creer(string $email, string $motDePasse): User
    {
        if (null !== $this->utilisateurs->findOneBy(['email' => $email])) {
            throw new EmailDejaUtilise($email);
        }

        $user = new User($email, ['ROLE_PRO']);
        $user->definirMotDePasse($this->hasher->hashPassword($user, $motDePasse));
        $this->utilisateurs->enregistrer($user);

        return $user;
    }
}
