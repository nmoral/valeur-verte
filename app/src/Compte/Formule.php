<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Formule d'un compte (#61). Deux types au démarrage : « fondateur » (essai
 * long, quota généreux, plafonné à quelques comptes — pour les testeurs des
 * débuts) et « classique » (la formule courante). D'autres suivront.
 *
 * Les paramètres portés ici sont les **défauts** ; ils restent éditables en base
 * ({@see ReglagesFormuleRepository}), jamais figés en dur (#65).
 */
enum Formule: string
{
    case Classique = 'classique';
    case Fondateur = 'fondateur';

    public function libelle(): string
    {
        return match ($this) {
            self::Classique => 'Classique',
            self::Fondateur => 'Fondateur',
        };
    }

    public function parametresParDefaut(): ParametresFormule
    {
        return match ($this) {
            self::Classique => new ParametresFormule(dureeEssaiJours: 60, quotaRapports: 10, plafondComptes: null),
            self::Fondateur => new ParametresFormule(dureeEssaiJours: 180, quotaRapports: 100, plafondComptes: 10),
        };
    }
}
