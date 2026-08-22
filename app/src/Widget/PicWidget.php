<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Un pic constaté sur une page publique (#268, épic #6) : le motif, la mesure sur
 * la fenêtre, le seuil franchi, et si le dépassement est critique.
 *
 * Objet de constat, sans décision : c'est {@see SurveillanceAntiAbusWidget} qui en
 * tire l'escalade (alerte, défi durci, coupure).
 */
final readonly class PicWidget
{
    public function __construct(
        private MotifPicWidget $motif,
        private int $mesure,
        private int $seuil,
        private bool $critique,
    ) {
    }

    public function motif(): MotifPicWidget
    {
        return $this->motif;
    }

    public function mesure(): int
    {
        return $this->mesure;
    }

    public function seuil(): int
    {
        return $this->seuil;
    }

    public function estCritique(): bool
    {
        return $this->critique;
    }

    /**
     * Ampleur du dépassement, qui départage deux seuils franchis en même temps.
     */
    public function ampleur(): float
    {
        return $this->mesure / $this->seuil;
    }

    public function resume(): string
    {
        return \sprintf('%d %s sur la fenêtre (seuil %d)', $this->mesure, $this->motif->libelle(), $this->seuil);
    }
}
