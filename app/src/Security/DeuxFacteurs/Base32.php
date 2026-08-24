<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

/**
 * Base32 (RFC 4648) — l'encodage sous lequel un secret TOTP voyage vers
 * l'application d'authentification (#312). PHP n'en fournit pas d'implémentation
 * native, contrairement à base64.
 */
final class Base32
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encoder(string $binaire): string
    {
        $bits = '';
        foreach (str_split($binaire) as $octet) {
            $bits .= str_pad(decbin(\ord($octet)), 8, '0', \STR_PAD_LEFT);
        }

        $encode = '';
        // Le dernier quintet incomplet est complété par des zéros — le décodeur
        // ignore les bits en trop, qui ne forment pas un octet.
        foreach (str_split($bits, 5) as $quintet) {
            // bindec rend int|float — float seulement au-delà de PHP_INT_MAX,
            // impossible sur cinq bits. Le cast le dit à l'analyse statique.
            $encode .= self::ALPHABET[(int) bindec(str_pad($quintet, 5, '0', \STR_PAD_RIGHT))];
        }

        return $encode;
    }

    /**
     * @throws \InvalidArgumentException si l'entrée n'est pas du base32
     */
    public static function decoder(string $base32): string
    {
        // Le remplissage « = » et la casse sont tolérés : un secret recopié à la
        // main depuis un gestionnaire de mots de passe arrive sous des formes
        // variées. Tout le reste est un secret invalide, pas un secret à deviner.
        $normalise = strtoupper(rtrim($base32, '='));
        if ('' === $normalise || 1 !== preg_match('/^[A-Z2-7]+$/', $normalise)) {
            throw new \InvalidArgumentException('Secret base32 invalide.');
        }

        $bits = '';
        foreach (str_split($normalise) as $caractere) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $caractere)), 5, '0', \STR_PAD_LEFT);
        }

        $binaire = '';
        // Le dernier groupe incomplet est du remplissage : il ne porte pas d'octet.
        foreach (str_split($bits, 8) as $octet) {
            if (8 === \strlen($octet)) {
                $binaire .= \chr((int) bindec($octet));
            }
        }

        return $binaire;
    }
}
