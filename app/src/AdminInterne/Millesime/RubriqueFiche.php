<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

/**
 * Groupe de critères d'une fiche de maille, regroupés par ce qu'ils évaluent
 * (fiabilité, périmètre, effet du DPE…) plutôt que par leur origine technique.
 */
final readonly class RubriqueFiche
{
    /**
     * @param list<Critere> $criteres
     */
    public function __construct(
        private string $titre,
        private array $criteres,
    ) {
    }

    public function titre(): string
    {
        return $this->titre;
    }

    /**
     * @return list<Critere>
     */
    public function criteres(): array
    {
        return $this->criteres;
    }

    public function toutRespecte(): bool
    {
        foreach ($this->criteres as $critere) {
            if (false === $critere->respecte()) {
                return false;
            }
        }

        return true;
    }
}
