<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Empreinte d'effacement d'un compte (#332). Signe l'adresse **canonique** (#331)
 * par HMAC-SHA256 avec le secret applicatif : à l'effacement (#308) on conserve
 * cette empreinte, jamais l'adresse en clair, pour reconnaître une réinscription et
 * ne pas rendre un second essai. L'entrée étant une adresse — devinable par
 * dictionnaire —, le HMAC (secret hors base) est ce qui rend l'empreinte
 * inexploitable si un dump fuite, là où un SHA-256 nu ne suffirait pas.
 */
final readonly class EmpreinteEffacement
{
    public function __construct(private string $secret)
    {
    }

    public function pour(string $emailCanonique): string
    {
        return hash_hmac('sha256', 'effacement:'.$emailCanonique, $this->secret);
    }

    public function correspond(string $emailCanonique, string $empreinte): bool
    {
        return hash_equals($this->pour($emailCanonique), $empreinte);
    }
}
