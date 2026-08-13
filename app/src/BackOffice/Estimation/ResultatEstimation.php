<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use App\BackOffice\Restitution\Restitution;

/**
 * Résultat complet d'une estimation (#33) : la restitution présentée (§5.3) et
 * le contexte de calcul employé, à figer dans l'instantané pour la reproductibilité.
 */
final readonly class ResultatEstimation
{
    private function __construct(
        private Restitution $restitution,
        private ContexteCalcul $contexte,
    ) {
    }

    public static function depuis(Restitution $restitution, ContexteCalcul $contexte): self
    {
        return new self($restitution, $contexte);
    }

    public function restitution(): Restitution
    {
        return $this->restitution;
    }

    public function contexte(): ContexteCalcul
    {
        return $this->contexte;
    }
}
