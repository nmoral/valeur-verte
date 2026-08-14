<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Jeton de désabonnement à la relance de fin d'essai (#108). Signe le tenant par
 * HMAC-SHA256 avec le secret applicatif : le lien de désabonnement placé dans
 * l'email reste valide sans session ni stockage, et infalsifiable sans le secret.
 */
final readonly class JetonDesabonnementRelance
{
    public function __construct(private string $secret)
    {
    }

    public function pour(int $tenantId): string
    {
        return hash_hmac('sha256', 'desabonnement-relance:'.$tenantId, $this->secret);
    }

    public function estValide(int $tenantId, string $jeton): bool
    {
        return hash_equals($this->pour($tenantId), $jeton);
    }
}
