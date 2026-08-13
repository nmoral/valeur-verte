<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

/**
 * Seuils d'alerte de la supervision des coefficients (#45) : au-delà, une maille
 * est signalée avant qu'un pro ne l'affiche. Le gain E→C est un effet log
 * (coef_C − coef_E), pas un montant € — la conversion € est un chantier ultérieur.
 */
final readonly class SeuilsSupervision
{
    private function __construct(
        private float $erreurMedianeMaxPct,
        private float $gainEcMaxLog,
    ) {
    }

    public static function de(float $erreurMedianeMaxPct, float $gainEcMaxLog): self
    {
        if ($erreurMedianeMaxPct <= 0.0 || $gainEcMaxLog <= 0.0) {
            throw new \InvalidArgumentException('Les seuils de supervision doivent être strictement positifs.');
        }

        return new self($erreurMedianeMaxPct, $gainEcMaxLog);
    }

    public function erreurMedianeMaxPct(): float
    {
        return $this->erreurMedianeMaxPct;
    }

    public function gainEcMaxLog(): float
    {
        return $this->gainEcMaxLog;
    }
}
