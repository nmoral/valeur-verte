<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

/**
 * Vérifie un code à six chiffres contre le second facteur d'un compte (#312).
 *
 * Le compromis tenu ici est le cœur de la fonctionnalité :
 *
 * - **une fenêtre de tolérance de part et d'autre** (±30 s). Le téléphone n'est
 *   pas à la seconde près et l'admin met quelques secondes à recopier ; refuser
 *   pour si peu produit des rejets incompréhensibles, et un second facteur qu'on
 *   ne comprend pas finit désactivé. Deux fenêtres, en revanche, offriraient une
 *   minute et demie de vie à un code volé ;
 * - **le pas accepté est consommé et fait plancher** : le même code ne resert
 *   pas, et aucun code plus ancien non plus. Sans cela, la tolérance qu'on vient
 *   d'accorder deviendrait une seconde chance pour qui a lu l'écran par-dessus
 *   l'épaule.
 *
 * La comparaison passe par `hash_equals` : comparer deux chaînes avec `===` fuit
 * leur préfixe commun par le temps de calcul.
 */
final readonly class VerificateurSecondFacteur
{
    private const int FENETRES_TOLEREES = 1;

    public function verifier(SecondFacteur $facteur, string $codeSaisi, \DateTimeImmutable $maintenant): bool
    {
        if (!$facteur->estActif()) {
            return false;
        }

        return $this->correspond($facteur, $codeSaisi, $maintenant);
    }

    /**
     * Éprouve le code d'un facteur **encore en attente**, pendant l'enrôlement.
     *
     * Une méthode à part plutôt qu'une activation provisoire de l'entité : celle-ci
     * est gérée par Doctrine, et l'activer « juste pour vérifier » ferait persister
     * au premier flush venu un second facteur qui n'a jamais été confirmé. Le
     * garde-fou de {@see verifier()} — un facteur inactif n'ouvre rien — resterait
     * en place, mais le compte se retrouverait protégé par un téléphone dont
     * personne n'a prouvé la possession.
     */
    public function verifierPourEnrolement(SecondFacteur $enAttente, string $codeSaisi, \DateTimeImmutable $maintenant): bool
    {
        return $this->correspond($enAttente, $codeSaisi, $maintenant);
    }

    private function correspond(SecondFacteur $facteur, string $codeSaisi, \DateTimeImmutable $maintenant): bool
    {
        $totp = new CodeTotp($facteur->secret());
        $pasCourant = $totp->pas($maintenant);

        for ($decalage = -self::FENETRES_TOLEREES; $decalage <= self::FENETRES_TOLEREES; ++$decalage) {
            $instant = $maintenant->modify(\sprintf('%+d seconds', $decalage * CodeTotp::PAS_SECONDES));
            $pas = $pasCourant + $decalage;

            if (!$facteur->pasEstConsommable($pas)) {
                continue;
            }

            if (hash_equals($totp->pour($instant), $codeSaisi)) {
                $facteur->consommerPas($pas);

                return true;
            }
        }

        return false;
    }
}
