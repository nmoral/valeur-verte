<?php

declare(strict_types=1);

namespace App\Onboarding\Parcours;

/**
 * Étapes du parcours d'inscription self-service (#111).
 *
 * Le funnel se lit dans cet ordre : écran d'inscription affiché → SIRET renseigné
 * → compte créé. L'**abandon** n'a pas d'événement : il se lit comme l'absence de
 * l'étape suivante — un écran affiché sans compte créé est un abandon, et c'est
 * l'écran SIRET (le seul moment où l'on demande quelque chose avant de rien donner)
 * que ce funnel surveille en priorité.
 *
 * Socle calqué sur le parcours widget (#90) mais **isolé** : le parcours pro est
 * pré-compte et anonyme (pas de token de page, pas de tenant), et son régime RGPD
 * n'est pas celui du widget.
 */
enum TypeEvenementInscription: string
{
    case InscriptionAffichee = 'inscription_affichee';
    case SiretRenseigne = 'siret_renseigne';
    case CompteCree = 'compte_cree';
}
