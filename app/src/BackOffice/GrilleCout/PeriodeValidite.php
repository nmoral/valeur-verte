<?php

declare(strict_types=1);

namespace App\BackOffice\GrilleCout;

/**
 * Période de validité d'une grille de coûts (versionnement valide_du / valide_au, décision D4).
 *
 * Objet immuable : clôturer une période produit une nouvelle instance, jamais une
 * modification destructive de l'existante (invariant produit §7).
 */
final readonly class PeriodeValidite
{
    private function __construct(
        private \DateTimeImmutable $valideDu,
        private ?\DateTimeImmutable $valideAu,
    ) {
    }

    public static function depuis(\DateTimeImmutable $valideDu): self
    {
        return new self($valideDu, null);
    }

    public function fermerLe(\DateTimeImmutable $valideAu): self
    {
        if ($valideAu < $this->valideDu) {
            throw new \InvalidArgumentException('La clôture ne peut pas précéder le début de validité.');
        }

        return new self($this->valideDu, $valideAu);
    }

    public function estActiveLe(\DateTimeImmutable $instant): bool
    {
        if ($instant < $this->valideDu) {
            return false;
        }

        return null === $this->valideAu || $instant < $this->valideAu;
    }

    public function valideDu(): \DateTimeImmutable
    {
        return $this->valideDu;
    }

    public function valideAu(): ?\DateTimeImmutable
    {
        return $this->valideAu;
    }
}
