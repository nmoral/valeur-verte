<?php

declare(strict_types=1);

namespace App\Onboarding\Parcours;

use Symfony\Component\Clock\ClockInterface;

/**
 * Point d'entrée unique de la collecte du parcours d'inscription (#111) : horodate
 * l'étape et la persiste. Anonyme (aucune donnée personnelle), calqué sur le
 * journal du parcours widget (#90) mais isolé du token/tenant.
 */
final readonly class JournalParcoursInscription
{
    public function __construct(
        private EvenementInscriptionRepository $evenements,
        private ClockInterface $horloge,
    ) {
    }

    /**
     * @param array<string, scalar|null> $donnees
     */
    public function enregistrer(string $sessionId, TypeEvenementInscription $type, array $donnees = []): void
    {
        $this->evenements->enregistrer(
            EvenementInscription::survenu($sessionId, $type, $this->horloge->now(), $donnees),
        );
    }
}
