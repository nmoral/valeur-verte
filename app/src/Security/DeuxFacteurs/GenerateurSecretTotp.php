<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

/**
 * Secret partagé du second facteur (#312) et l'URI qui le transmet.
 *
 * Le secret est stocké **en clair** — contrairement à un mot de passe ou à un
 * code de secours, il n'est pas comparé mais **rejoué** : le serveur doit
 * recalculer le même code que le téléphone. Le hacher le rendrait inutilisable.
 * C'est ce qui justifie de le purger des dumps restaurés hors production.
 */
final class GenerateurSecretTotp
{
    // 160 bits : la taille recommandée par la RFC 4226 (§4, R6), et celle
    // qu'attendent les applications d'authentification.
    private const int OCTETS = 20;

    public function nouveau(): string
    {
        return Base32::encoder(random_bytes(self::OCTETS));
    }

    /**
     * URI que le QR encode. Le libellé « émetteur:compte » est ce que l'admin
     * lira dans son application ; l'émetteur est répété en paramètre, les deux
     * emplacements étant lus selon les applications.
     *
     * Aucun paramètre au-delà du secret et de l'émetteur : Google Authenticator
     * force de toute façon SHA1 / 6 chiffres / 30 secondes, et CodeTotp calcule
     * exactement cela.
     */
    public function uriOtpauth(string $secretBase32, string $compte, string $emetteur): string
    {
        return \sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s',
            // Les deux moitiés sont encodées séparément : le deux-points qui les
            // sépare est un séparateur de la spécification, pas un caractère du
            // libellé — encodé, certaines applications n'y lisent plus l'émetteur.
            rawurlencode($emetteur).':'.rawurlencode($compte),
            $secretBase32,
            rawurlencode($emetteur),
        );
    }
}
