<?php

declare(strict_types=1);

namespace App\BackOffice\Bouquet;

/**
 * Bouquets proposés à la sélection en rendez-vous (#29, §9.2). Tant que la
 * configuration back-office (#5) n'est pas branchée, la liste par défaut — les
 * trois bouquets types — reste exploitable, dans l'esprit « un rapport avant
 * toute configuration ». La sélection se fait par nom.
 */
final readonly class BouquetsDisponibles
{
    /**
     * @return list<Bouquet>
     */
    public function disponibles(): array
    {
        return [
            Bouquet::renovationAmpleurStandard(),
            Bouquet::isolationSeule(),
            Bouquet::chauffageSeul(),
        ];
    }

    public function parNom(string $nom): ?Bouquet
    {
        foreach ($this->disponibles() as $bouquet) {
            if ($bouquet->nom() === $nom) {
                return $bouquet;
            }
        }

        return null;
    }
}
