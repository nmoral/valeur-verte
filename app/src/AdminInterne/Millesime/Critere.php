<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

/**
 * Un critère d'évaluation d'une maille, rendu interprétable : sa valeur, la borne
 * qu'il doit respecter, s'il la respecte, et une phrase qui dit ce qu'il signifie.
 *
 * `respecte` vaut `null` pour un critère **informatif**, sans borne — donner un
 * verdict à un indicateur qui n'en porte pas lui prêterait un statut de critère de
 * décision qu'il n'a pas.
 */
final readonly class Critere
{
    public function __construct(
        private string $libelle,
        private string $valeur,
        private ?string $seuil = null,
        private ?bool $respecte = null,
        private ?string $explication = null,
    ) {
    }

    public function libelle(): string
    {
        return $this->libelle;
    }

    public function valeur(): string
    {
        return $this->valeur;
    }

    public function seuil(): ?string
    {
        return $this->seuil;
    }

    public function respecte(): ?bool
    {
        return $this->respecte;
    }

    public function explication(): ?string
    {
        return $this->explication;
    }

    public function estInformatif(): bool
    {
        return null === $this->respecte;
    }
}
