<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

/**
 * Gravité d'un constat d'audit SEO (#345).
 *
 * Trois niveaux, du plus anodin au plus grave : `Ok` (la règle est respectée),
 * `Alerte` (défaut à corriger mais sans conséquence bloquante sur l'indexation —
 * titre trop long, canonical manquante), `Erreur` (défaut qui empêche ou fausse
 * l'indexation — titre absent, page en 500). La valeur `string` est stable :
 * elle est **persistée** dans l'instantané JSONB du rapport et sert de clé de
 * regroupement dans la restitution admin.
 */
enum Gravite: string
{
    case Ok = 'ok';
    case Alerte = 'alerte';
    case Erreur = 'erreur';

    /**
     * Poids du défaut dans le score technique (#345) : un `Ok` ne coûte rien, une
     * `Alerte` compte pour un, une `Erreur` pour trois. L'écart 1↔3 traduit qu'une
     * erreur (page morte, noindex) pèse plus lourd qu'un défaut cosmétique.
     */
    public function penalite(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Alerte => 1,
            self::Erreur => 3,
        };
    }
}
