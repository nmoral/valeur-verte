<?php

declare(strict_types=1);

namespace App\Liste;

/**
 * Liste blanche des colonnes triables d'une liste, avec sa colonne par défaut.
 * C'est la frontière de confiance du tri : seule une clé logique déclarée ici
 * atteint la couche de données, jamais un nom SQL brut venu de la requête.
 */
final readonly class ColonnesTriables
{
    /**
     * @param list<string> $colonnes
     */
    private function __construct(
        private string $defaut,
        private array $colonnes,
    ) {
    }

    public static function de(string $defaut, string ...$autres): self
    {
        if ('' === trim($defaut)) {
            throw new \InvalidArgumentException('La colonne de tri par défaut ne peut pas être vide.');
        }

        $colonnes = [];
        foreach ([$defaut, ...$autres] as $colonne) {
            if (!\in_array($colonne, $colonnes, true)) {
                $colonnes[] = $colonne;
            }
        }

        return new self($defaut, $colonnes);
    }

    public function contient(string $colonne): bool
    {
        return \in_array($colonne, $this->colonnes, true);
    }

    public function normaliser(?string $tri): string
    {
        return null !== $tri && $this->contient($tri) ? $tri : $this->defaut;
    }

    public function defaut(): string
    {
        return $this->defaut;
    }

    /**
     * @return list<string>
     */
    public function toutes(): array
    {
        return $this->colonnes;
    }
}
