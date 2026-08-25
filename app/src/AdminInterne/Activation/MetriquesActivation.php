<?php

declare(strict_types=1);

namespace App\AdminInterne\Activation;

/**
 * Instantané des métriques d'activation du parcours self-service (#111), lues des
 * horodatages déjà en base. Le délai médian est nul tant qu'aucun compte n'a
 * produit de rapport (médiane d'un ensemble vide).
 */
final readonly class MetriquesActivation
{
    public function __construct(
        private Taux $premierRapport,
        private ?int $delaiMedianMinutes,
        private Taux $troisRapportsAJTrente,
    ) {
    }

    public function premierRapport(): Taux
    {
        return $this->premierRapport;
    }

    public function delaiMedianMinutes(): ?int
    {
        return $this->delaiMedianMinutes;
    }

    public function troisRapportsAJTrente(): Taux
    {
        return $this->troisRapportsAJTrente;
    }
}
