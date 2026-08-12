<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Statut RGE d'une entreprise. « Non RGE » ne bloque pas l'inscription : c'est
 * un segment à observer. « Indéterminé » distingue une réponse manquante de
 * l'ADEME (API indisponible) d'une absence confirmée de qualification.
 */
enum StatutRge: string
{
    case Rge = 'rge';
    case NonRge = 'non_rge';
    case Indetermine = 'indetermine';

    public function libelle(): string
    {
        return match ($this) {
            self::Rge => 'RGE',
            self::NonRge => 'Non RGE',
            self::Indetermine => 'Indéterminé',
        };
    }
}
