<?php

declare(strict_types=1);

namespace App\PourquoiIci;

/**
 * Les marqueurs d'une zone, indexés par nom (#341, Lot 3).
 *
 * Vue en lecture des lignes de `data.marqueurs` d'une commune ou d'un département,
 * contextuels et mesurés confondus (le moteur teste indifféremment `climat` ou
 * `levier_renovation`). Immuable : la lecture DBAL (Lot 4) construit cette vue,
 * le moteur ne fait que l'interroger.
 */
final readonly class Marqueurs
{
    /**
     * @param array<string, string> $valeurs nom du marqueur → valeur
     */
    private function __construct(private array $valeurs)
    {
    }

    /**
     * @param array<string, string> $valeurs
     */
    public static function depuis(array $valeurs): self
    {
        return new self($valeurs);
    }

    public function valeur(string $nom): ?string
    {
        return $this->valeurs[$nom] ?? null;
    }

    public function a(string $nom, string $valeur): bool
    {
        return ($this->valeurs[$nom] ?? null) === $valeur;
    }

    /**
     * Une copie enrichie d'un marqueur (ajouté ou remplacé) — l'original est
     * inchangé. Sert à injecter un marqueur dérivé, comme le levier départemental
     * (#341, Lot 4), là où la source ne le porte pas.
     */
    public function avec(string $nom, string $valeur): self
    {
        return new self([...$this->valeurs, $nom => $valeur]);
    }
}
