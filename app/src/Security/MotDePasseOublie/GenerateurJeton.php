<?php

declare(strict_types=1);

namespace App\Security\MotDePasseOublie;

/**
 * Génère le jeton de réinitialisation (#194) et son empreinte. Le jeton brut
 * (256 bits d'aléa) voyage dans l'URL de l'email ; seule son empreinte SHA-256
 * est stockée, si bien qu'une fuite de la base ne permet aucune réinitialisation.
 */
final class GenerateurJeton
{
    public function jetonBrut(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function empreinte(string $jetonBrut): string
    {
        return hash('sha256', $jetonBrut);
    }
}
