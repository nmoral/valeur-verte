<?php

declare(strict_types=1);

namespace App\AdminInterne\Activation;

use App\Onboarding\Parcours\EvenementInscriptionRepository;
use App\Onboarding\Parcours\TypeEvenementInscription;

/**
 * Funnel d'inscription self-service (#111) : lit les événements anonymes du
 * parcours (collectés par le socle Onboarding) et en dérive le taux d'abandon sur
 * l'écran SIRET — le signal prioritaire de l'issue, seul moment où l'on demande
 * quelque chose avant de rien donner. Lecture seule.
 */
final readonly class CalculFunnelInscription
{
    public function __construct(private EvenementInscriptionRepository $evenements)
    {
    }

    public function calculer(): FunnelInscription
    {
        $parType = $this->evenements->compterSessionsParType();

        return new FunnelInscription(
            $parType[TypeEvenementInscription::InscriptionAffichee->value] ?? 0,
            $parType[TypeEvenementInscription::SiretRenseigne->value] ?? 0,
            $parType[TypeEvenementInscription::CompteCree->value] ?? 0,
        );
    }
}
