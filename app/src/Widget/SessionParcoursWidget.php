<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Identifiant de session du parcours widget (#90, épic #6).
 *
 * Relie entre elles les étapes d'une même visite (chargement → estimation →
 * mur de contact → lead) pour mesurer **où le visiteur décroche**, sans jamais
 * l'identifier : l'identifiant est tiré au hasard, **éphémère** (il ne vit que
 * dans les formulaires de la visite en cours, aucun cookie, aucun stockage
 * navigateur) et ne dérive d'aucune donnée personnelle — ni IP, ni email, ni
 * empreinte de navigateur. Deux visites, deux identifiants sans lien entre eux.
 *
 * Format fixe (32 caractères hexadécimaux) : l'endpoint public de collecte
 * écarte ainsi d'emblée ce qui n'en est pas un.
 */
final class SessionParcoursWidget
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
