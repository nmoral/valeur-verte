<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use App\BackOffice\Restitution\EffetDeSeuil;
use App\BackOffice\Restitution\Restitution;

/**
 * Résultat complet d'une estimation (#33) : la restitution présentée (§5.3), le
 * contexte de calcul employé et l'effet de seuil (#27), tous à figer dans
 * l'instantané pour la reproductibilité.
 */
final readonly class ResultatEstimation
{
    private function __construct(
        private Restitution $restitution,
        private ContexteCalcul $contexte,
        private ?EffetDeSeuil $effetDeSeuil,
    ) {
    }

    public static function depuis(Restitution $restitution, ContexteCalcul $contexte, ?EffetDeSeuil $effetDeSeuil = null): self
    {
        return new self($restitution, $contexte, $effetDeSeuil);
    }

    public function restitution(): Restitution
    {
        return $this->restitution;
    }

    public function contexte(): ContexteCalcul
    {
        return $this->contexte;
    }

    public function effetDeSeuil(): ?EffetDeSeuil
    {
        return $this->effetDeSeuil;
    }
}
