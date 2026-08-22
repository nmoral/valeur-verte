<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Une adresse proposée au visiteur de la page publique (#287) : son libellé, et
 * le **code INSEE** de sa commune — la maille du moteur (§10.6).
 *
 * Les deux vont toujours ensemble : c'est la sélection dans la liste qui rend le
 * code INSEE fiable. Une adresse saisie librement n'en porte aucun, et ne peut
 * donc pas être chiffrée sans risquer la mauvaise commune.
 */
final readonly class AdresseSuggeree
{
    private function __construct(
        private string $libelle,
        private string $codeInsee,
    ) {
    }

    public static function creer(string $libelle, string $codeInsee): self
    {
        return new self(trim($libelle), trim($codeInsee));
    }

    public function libelle(): string
    {
        return $this->libelle;
    }

    public function codeInsee(): string
    {
        return $this->codeInsee;
    }
}
