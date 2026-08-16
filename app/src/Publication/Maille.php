<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Une maille du millésime publié, telle que la page publique la lit (#48, #224).
 *
 * Les coefficients DPE sont des **effets log** (référentiel moteur §4) : le gain
 * lisible par un lecteur est ``exp(coef_vers − coef_depuis) − 1``, en pourcentage.
 */
final readonly class Maille
{
    /**
     * @param array<string, float> $coefDpe effets log par classe DPE, base D
     */
    public function __construct(
        public string $codeInsee,
        public string $mode,
        public array $coefDpe,
    ) {
    }

    public function departement(): string
    {
        return substr($this->codeInsee, 0, 2);
    }

    /**
     * Gain de valeur en pourcentage entre deux classes, ou ``null`` si l'une des
     * deux n'a pas été publiée sur cette maille (effectif insuffisant, §9).
     */
    public function gain(string $depuis, string $vers): ?int
    {
        if (!isset($this->coefDpe[$depuis], $this->coefDpe[$vers])) {
            return null;
        }

        return (int) round((exp($this->coefDpe[$vers] - $this->coefDpe[$depuis]) - 1) * 100);
    }

    public function estDepartementale(): bool
    {
        return 'departemental' === $this->mode;
    }
}
