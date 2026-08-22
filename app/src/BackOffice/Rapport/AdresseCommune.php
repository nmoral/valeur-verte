<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

/**
 * Bornage d'une adresse à sa commune (décision D10, #68).
 *
 * À l'échéance de rétention, un rapport ne doit plus désigner un logement. Les
 * adresses stockées viennent de la BAN — « 12 Rue des Lilas 31000 Toulouse » —
 * dans les deux parcours (audit pro et page publique) : le code postal y sépare
 * la voie de la commune. On coupe donc au **dernier** groupe de cinq chiffres,
 * pour qu'un millésime dans un nom de voie (« Rue du 8 Mai 1945 ») ou un numéro
 * inhabituel ne prenne pas sa place.
 *
 * Sans code postal (saisie libre), rien n'est conservé : mieux vaut perdre une
 * commune que laisser filer une voie.
 */
final class AdresseCommune
{
    public static function borner(string $adresse): string
    {
        // Le dernier groupe de cinq chiffres, et tout ce qui le suit.
        if (1 !== preg_match('/\b\d{5}\b(?!.*\b\d{5}\b).*$/s', $adresse, $trouve)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', $trouve[0]));
    }
}
