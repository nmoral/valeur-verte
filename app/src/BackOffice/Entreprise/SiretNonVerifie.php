<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Levée quand un SIRET **nouveau** ne se retrouve pas auprès de l'API entreprise
 * (#106) : entreprise inconnue, fermée, ou source indisponible. Le changement est
 * alors refusé — le SIRET identifie le redevable, et une facture émise au mauvais
 * nom se corrige mal après coup (#311).
 *
 * Ne concerne que le **changement** : un SIRET déjà en fiche n'est jamais remis en
 * cause, sans quoi une source muette gèlerait la correction d'une faute de frappe.
 */
final class SiretNonVerifie extends \RuntimeException
{
    public static function pour(Siret $siret): self
    {
        return new self(\sprintf(
            'Le SIRET %s n\'a pas pu être vérifié auprès de l\'annuaire des entreprises : '
            .'vérifiez la saisie, ou réessayez plus tard si le service est indisponible.',
            $siret->valeur(),
        ));
    }
}
