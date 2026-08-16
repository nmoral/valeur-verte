<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Jeton de désabonnement aux emails non transactionnels (#108/#109). Signe le
 * tenant par HMAC-SHA256 avec le secret applicatif : le lien de désabonnement
 * placé dans un email reste valide sans session ni stockage, et infalsifiable
 * sans le secret. Un seul désabonnement couvre tous les emails non
 * transactionnels (relance de fin d'essai, relance après premier rapport…).
 */
final readonly class JetonDesabonnement
{
    public function __construct(private string $secret)
    {
    }

    public function pour(int $tenantId): string
    {
        return hash_hmac('sha256', 'desabonnement:'.$tenantId, $this->secret);
    }

    public function estValide(int $tenantId, string $jeton): bool
    {
        return hash_equals($this->pour($tenantId), $jeton);
    }
}
