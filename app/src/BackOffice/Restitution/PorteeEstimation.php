<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

/**
 * Cran de la cascade géographique (#169) porté par la maille (colonne ``mode``).
 * Le rayon ne suffit plus à situer l'estimation : les crans EPCI et
 * DÉPARTEMENT ont tous deux un rayon absent, seul le cran les distingue. La
 * portée en dérive sa formulation (phrase et statistique de restitution).
 */
enum PorteeEstimation: string
{
    case Local = 'local';
    case Epci = 'epci';
    case Departemental = 'departemental';

    /**
     * Traduit le contrat data→app. La colonne ``mode`` fait foi quand elle est
     * renseignée ; une ligne héritée (avant #169, ``mode`` absent) se lit à
     * l'ancienne — un rayon présent est local, un rayon absent le repli
     * départemental.
     */
    public static function depuis(?string $mode, ?int $rayonKm): self
    {
        return self::tryFrom($mode ?? '')
            ?? (null === $rayonKm ? self::Departemental : self::Local);
    }

    /**
     * Fragment inséré dans la phrase de restitution : « … sur N transactions
     * <formulation> ».
     */
    public function formulation(?int $rayonKm): string
    {
        return match ($this) {
            self::Local => \sprintf('dans un rayon de %d km', $rayonKm),
            self::Epci => 'au niveau intercommunal',
            self::Departemental => 'au niveau départemental',
        };
    }

    /**
     * Libellé court de la cellule « Rayon retenu » des statistiques.
     */
    public function intitule(?int $rayonKm): string
    {
        return match ($this) {
            self::Local => \sprintf('%d km', $rayonKm),
            self::Epci => 'intercommunal (EPCI)',
            self::Departemental => 'départemental',
        };
    }
}
