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
 *
 * S'y ajoutent les **rejets** des contrôles préventifs (#43) — plafond atteint,
 * honeypot déclenché, jeton de chargement refusé, Turnstile refusé. Ce ne sont pas des étapes du funnel mais
 * des parcours refusés : les journaliser ici donne à l'anti-abus réactif (#268) un
 * taux d'échec lisible sur la même fenêtre glissante que le trafic légitime.
 */
enum TypeEvenementWidget: string
{
    case WidgetCharge = 'widget_charge';
    case AdresseSaisie = 'adresse_saisie';
    case EstimationAffichee = 'estimation_affichee';
    case DetailDeplie = 'detail_deplie';
    case MurContactAffiche = 'mur_contact_affiche';
    case LeadSoumis = 'lead_soumis';

    case PlafondAtteint = 'plafond_atteint';
    case PiegeDeclenche = 'piege_declenche';
    case JetonRefuse = 'jeton_refuse';
    case TurnstileEchoue = 'turnstile_echoue';

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

    /**
     * Vrai pour les parcours refusés par un contrôle préventif (#43) : ils ne font
     * pas partie du funnel et se lisent comme un taux d'échec (#268).
     */
    public function estRejet(): bool
    {
        return match ($this) {
            self::PlafondAtteint, self::PiegeDeclenche, self::JetonRefuse, self::TurnstileEchoue => true,
            default => false,
        };
    }

    /**
     * Vrai pour les types qu'un motif de pic mesure (#268) : eux seuls valent une
     * évaluation à l'enregistrement. La liste se **dérive** des motifs plutôt que
     * de s'y recopier — un motif qui gagne un type ne laisse pas d'angle mort.
     */
    public function declencheSurveillance(): bool
    {
        foreach (MotifPicWidget::cases() as $motif) {
            if (\in_array($this, $motif->typesMesures(), true)) {
                return true;
            }
        }

        return false;
    }
}
