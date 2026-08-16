<?php

declare(strict_types=1);

namespace App\Security\MonCompte;

use App\Security\User;
use App\Security\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Changement de mot de passe d'un compte connecté (#206, AUTH-3). Vérifie le mot
 * de passe actuel avant de poser le nouveau (haché via l'algorithme configuré).
 * Partagé par les deux zones (espace pro et admin interne) — un seul point de
 * changement, aucune duplication.
 */
final readonly class ChangementMotDePasse
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private UserRepository $utilisateurs,
    ) {
    }

    /**
     * @throws MotDePasseActuelInvalide
     */
    public function changer(User $user, string $actuel, string $nouveau): void
    {
        if (!$this->hasher->isPasswordValid($user, $actuel)) {
            throw new MotDePasseActuelInvalide();
        }

        $user->definirMotDePasse($this->hasher->hashPassword($user, $nouveau));
        $this->utilisateurs->enregistrer($user);
    }
}
