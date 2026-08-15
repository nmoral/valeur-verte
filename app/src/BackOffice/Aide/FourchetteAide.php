<?php

declare(strict_types=1);

namespace App\BackOffice\Aide;

use App\BackOffice\GrilleCout\Montant;

/**
 * Encadrement du montant d'aide quand la situation du ménage n'est pas précisée (#26).
 *
 * On ne devine pas la catégorie : l'information manquante reste visible sous forme
 * de fourchette plutôt que remplacée par un chiffre unique et faux.
 */
final readonly class FourchetteAide
{
    private function __construct(
        private Montant $minimum,
        private Montant $maximum,
    ) {
    }

    public static function de(Montant $minimum, Montant $maximum): self
    {
        if ($maximum->euros() < $minimum->euros()) {
            throw new \InvalidArgumentException('Le maximum d\'une fourchette ne peut pas être inférieur à son minimum.');
        }

        return new self($minimum, $maximum);
    }

    public function minimum(): Montant
    {
        return $this->minimum;
    }

    public function maximum(): Montant
    {
        return $this->maximum;
    }
}
