<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Tirage aléatoire du jeton d'une page publique (#38) : 16 octets rendus en
 * hexadécimal, soit les 32 caractères de la colonne. Le jeton ne dérive de rien
 * — ni du tenant, ni du nom du pro, ni d'une séquence — donc rien ne s'énumère
 * depuis une page connue.
 */
final class TirageJetonAleatoire implements TirageJeton
{
    private const int OCTETS = 16;

    public function tirer(): string
    {
        return bin2hex(random_bytes(self::OCTETS));
    }
}
