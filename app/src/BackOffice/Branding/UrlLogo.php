<?php

declare(strict_types=1);

namespace App\BackOffice\Branding;

/**
 * Validation de frontière de l'URL de logo du branding tenant (#267, épic #6).
 *
 * La page publique étant hébergée sur notre origine, cette URL est un vecteur
 * d'injection (XSS via le rendu) et de SSRF (fetch d'une adresse interne). On
 * n'accepte donc que :
 *  - un **chemin same-origin** (`/…`, logo hébergé chez nous), ou
 *  - une **URL absolue `https://`** vers un hôte **public** (ni privé, ni loopback,
 *    ni link-local — ce qui écarte notamment le point de métadonnées `169.254.169.254`).
 *
 * Aucune résolution DNS ici (garde de frontière, sans effet réseau) : seuls les
 * littéraux d'adresse sont filtrés ; le durcissement plus poussé (proxy du logo)
 * relève d'un lot ultérieur.
 */
final readonly class UrlLogo
{
    public static function valider(string $url): void
    {
        // Chemin relatif servi par notre origine (mais pas « //hôte » protocol-relative).
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return;
        }

        $parts = parse_url($url);
        if (false === $parts
            || 'https' !== ($parts['scheme'] ?? null)
            || '' === ($parts['host'] ?? '')
            || null === ($parts['host'] ?? null)
        ) {
            throw new \InvalidArgumentException(\sprintf('URL de logo invalide : « %s ». Attendu un chemin /… ou une URL https publique.', $url));
        }

        if (self::hoteInterdit($parts['host'])) {
            throw new \InvalidArgumentException(\sprintf('URL de logo interdite (adresse interne) : « %s ».', $url));
        }
    }

    private static function hoteInterdit(string $host): bool
    {
        $host = trim($host, '[]'); // littéral IPv6 entre crochets

        if ('localhost' === strtolower($host)) {
            return true;
        }

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            // Adresse littérale : interdite si privée ou réservée (loopback, link-local…).
            return false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }
}
