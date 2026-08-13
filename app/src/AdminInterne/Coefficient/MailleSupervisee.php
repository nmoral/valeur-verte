<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

/**
 * Ligne de supervision d'une maille (#45) : ses indicateurs de qualité et son
 * diagnostic d'aberration. Le rayon vaut null en repli départemental ; le gain
 * E→C (log) est null si la maille ne publie pas E ou C.
 */
final readonly class MailleSupervisee
{
    public function __construct(
        private string $codeInsee,
        private int $millesime,
        private int $n,
        private ?int $rayonKm,
        private float $erreurMediane,
        private ?float $gainEcLog,
        private DiagnosticMaille $diagnostic,
    ) {
    }

    public function codeInsee(): string
    {
        return $this->codeInsee;
    }

    public function millesime(): int
    {
        return $this->millesime;
    }

    public function n(): int
    {
        return $this->n;
    }

    public function rayonKm(): ?int
    {
        return $this->rayonKm;
    }

    public function erreurMediane(): float
    {
        return $this->erreurMediane;
    }

    public function gainEcLog(): ?float
    {
        return $this->gainEcLog;
    }

    public function estAberrante(): bool
    {
        return $this->diagnostic->estAberrante();
    }

    /**
     * @return list<string>
     */
    public function raisons(): array
    {
        return $this->diagnostic->raisons();
    }
}
