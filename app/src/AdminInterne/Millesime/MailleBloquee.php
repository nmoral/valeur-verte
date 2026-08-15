<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

/**
 * Maille écartée de la publication par REG-1 (#130), candidate à un déblocage.
 *
 * L'écart chiffré et le coefficient fautif accompagnent la commune : sans eux
 * l'opérateur arbitrerait à l'aveugle.
 */
final readonly class MailleBloquee
{
    public function __construct(
        private string $codeInsee,
        private float $ecart,
        private string $coefficient,
    ) {
    }

    public function codeInsee(): string
    {
        return $this->codeInsee;
    }

    /**
     * Écart relatif au millésime précédent (0,18 = 18 %).
     */
    public function ecart(): float
    {
        return $this->ecart;
    }

    public function ecartPourCent(): float
    {
        return round($this->ecart * 100, 1);
    }

    public function coefficient(): string
    {
        return $this->coefficient;
    }
}
