<?php

declare(strict_types=1);

namespace App\AdminInterne\Activation;

/**
 * Taux d'activation exprimé par son numérateur et son dénominateur : la page
 * admin (#111) montre le brut (« 3 / 4 comptes ») autant que le pourcentage, et
 * un dénominateur nul (aucune population) rend un pourcentage indéfini plutôt
 * qu'une division par zéro.
 */
final readonly class Taux
{
    public function __construct(
        private int $numerateur,
        private int $denominateur,
    ) {
    }

    public function numerateur(): int
    {
        return $this->numerateur;
    }

    public function denominateur(): int
    {
        return $this->denominateur;
    }

    public function pourcentage(): ?float
    {
        if (0 === $this->denominateur) {
            return null;
        }

        return round($this->numerateur / $this->denominateur * 100, 1);
    }
}
