<?php

declare(strict_types=1);

namespace App\Onboarding\Parcours;

/**
 * Identifiant de visite du parcours d'inscription (#111).
 *
 * Relie entre elles les étapes d'une même visite (écran affiché → SIRET renseigné
 * → compte créé) pour mesurer **où le visiteur décroche**, sans jamais l'identifier :
 * l'identifiant est tiré au hasard et ne dérive d'aucune donnée personnelle — ni
 * IP, ni email, ni SIRET. Format fixe (32 caractères hexadécimaux). Calqué sur
 * {@see \App\Widget\SessionParcoursWidget}.
 */
final class SessionParcoursInscription
{
    private const int OCTETS = 16;

    public function demarrer(): string
    {
        return bin2hex(random_bytes(self::OCTETS));
    }

    public function estValide(string $identifiant): bool
    {
        return 1 === preg_match('/^[0-9a-f]{'.(2 * self::OCTETS).'}$/', $identifiant);
    }
}
