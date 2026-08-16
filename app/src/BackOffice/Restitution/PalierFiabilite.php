<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

/**
 * Tier de fiabilité de la maille (#169, colonne ``palier``) : STANDARD sous le
 * seuil d'erreur médiane (20 %), DÉGRADÉ jusqu'au plafond de publication (30 %).
 * Second canal, distinct de l'indicateur Solide/Correct/Fragile (dérivé de n et
 * du rayon) : le tier dégradé impose son propre bandeau de restitution.
 */
enum PalierFiabilite: string
{
    case Standard = 'standard';
    case Degrade = 'degrade';

    /**
     * Traduit le contrat data→app : seul ``degrade`` marque le tier dégradé.
     * Toute autre valeur — ``standard``, absente (ligne héritée) ou inconnue —
     * retombe sur STANDARD, sans bandeau injustifié.
     */
    public static function depuis(?string $palier): self
    {
        return self::Degrade->value === $palier ? self::Degrade : self::Standard;
    }

    public function estDegrade(): bool
    {
        return self::Degrade === $this;
    }
}
