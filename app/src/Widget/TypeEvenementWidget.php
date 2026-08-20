<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Étapes du parcours widget (#90, épic #6).
 *
 * Le funnel se lit dans cet ordre : chargement de la page → adresse saisie →
 * premier chiffre affiché → détail déplié → mur de contact affiché → lead soumis.
 * L'**abandon** n'a pas d'événement : il se lit comme l'absence de l'étape suivante.
 *
 * Deux étapes n'existent que côté navigateur (l'utilisateur agit sans requête
 * serveur) : elles seules sont déclarables par l'endpoint public de collecte, pour
 * qu'on ne puisse pas fabriquer depuis l'extérieur un « lead soumis » ou un
 * « premier chiffre affiché » que le serveur seul constate.
 */
enum TypeEvenementWidget: string
{
    case WidgetCharge = 'widget_charge';
    case AdresseSaisie = 'adresse_saisie';
    case EstimationAffichee = 'estimation_affichee';
    case DetailDeplie = 'detail_deplie';
    case MurContactAffiche = 'mur_contact_affiche';
    case LeadSoumis = 'lead_soumis';

    /**
     * Vrai pour les seules étapes que le navigateur peut déclarer : le reste est
     * constaté côté serveur, donc jamais accepté de l'extérieur.
     */
    public function estDeclarableParLeVisiteur(): bool
    {
        return match ($this) {
            self::AdresseSaisie, self::DetailDeplie => true,
            default => false,
        };
    }
}
