<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Honeypot anti-bot des formulaires publics du widget (#43, contrôle 3, épic #6).
 *
 * Un champ leurre masqué (`site_web`) est rendu dans les formulaires : invisible et
 * hors du parcours clavier pour un humain, mais rempli par un bot naïf qui remplit
 * tout. Toute soumission où le leurre est renseigné est rejetée (422) — défense
 * sans friction, à jouer avant tout traitement coûteux (estimation, capture de lead).
 */
final class PiegeAntiBotWidget
{
    public const string CHAMP = 'site_web';

    public function verifier(Request $request): void
    {
        if ('' !== $request->request->getString(self::CHAMP)) {
            throw new UnprocessableEntityHttpException();
        }
    }
}
