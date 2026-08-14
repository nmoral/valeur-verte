<?php

declare(strict_types=1);

namespace App\Security\VerificationEmail;

/**
 * Jeton de vérification d'email (#105, ONB-1). Signe l'identifiant de l'utilisateur
 * par HMAC-SHA256 avec le secret applicatif : le lien de confirmation reste valide
 * sans session ni stockage, et infalsifiable sans le secret. La vérification étant
 * idempotente et jamais bloquante, un jeton stateless (ni usage unique ni
 * expiration) suffit — au contraire de la réinitialisation de mot de passe (#194).
 */
final readonly class JetonVerificationEmail
{
    public function __construct(private string $secret)
    {
    }

    public function pour(int $userId): string
    {
        return hash_hmac('sha256', 'verification-email:'.$userId, $this->secret);
    }

    public function estValide(int $userId, string $jeton): bool
    {
        return hash_equals($this->pour($userId), $jeton);
    }
}
