<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Comptes qui partagent une même identité (#331). Sert **avant** la migration qui
 * pose l'unicité : celle-ci échoue si l'existant porte déjà deux écritures d'une
 * même adresse, et un échec de migration au déploiement ne dit pas lesquelles.
 *
 * Pure : la liste des adresses entre, les groupes sortent. La règle d'identité
 * reste celle d'{@see AdresseEmail} — on ne la réécrit pas ici.
 */
final class DoublonsIdentite
{
    /**
     * @param array<int, string> $adressesParId
     *
     * @return array<string, array<int, string>> groupes de deux comptes ou plus,
     *                                           indexés par identité partagée
     */
    public static function detecter(array $adressesParId): array
    {
        $parIdentite = [];

        foreach ($adressesParId as $id => $adresse) {
            // Une adresse que la règle ne sait pas lire est groupée sur elle-même,
            // jamais écartée : elle bloquerait la migration en silence.
            $identite = AdresseEmail::estValide($adresse)
                ? AdresseEmail::depuis($adresse)->canonique()
                : $adresse;

            $parIdentite[$identite][$id] = AdresseEmail::estValide($adresse)
                ? AdresseEmail::depuis($adresse)->valeur()
                : $adresse;
        }

        return array_filter($parIdentite, static fn (array $comptes): bool => \count($comptes) > 1);
    }
}
