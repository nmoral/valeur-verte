<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Périmètre de publication des pages programmatiques SEO (#393).
 *
 * Mise en ligne **progressive** : au go-live, seuls sont exposés/indexés la carte
 * et les hubs des départements de cette allowlist ; les pages commune et EPCI ne le
 * sont que lorsque leur type est activé. Piloté par configuration
 * (``%app.perimetre.*%``, surchargeable par l'environnement) pour élargir par phases
 * sans changement de code — phase 1 : Occitanie, hubs départementaux seuls.
 */
final readonly class PerimetrePublic
{
    /**
     * @param list<string> $departements codes des départements publics
     */
    public function __construct(
        private array $departements,
        private bool $communesPubliques,
        private bool $epciPublics,
    ) {
    }

    public function contient(string $codeDepartement): bool
    {
        return \in_array($codeDepartement, $this->departements, true);
    }

    /**
     * @return list<string>
     */
    public function departements(): array
    {
        return $this->departements;
    }

    public function communesPubliques(): bool
    {
        return $this->communesPubliques;
    }

    public function epciPublics(): bool
    {
        return $this->epciPublics;
    }
}
