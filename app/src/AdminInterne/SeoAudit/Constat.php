<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

/**
 * Verdict d'une règle SEO sur une page (#345) : le code de la règle, sa gravité
 * et un message lisible qui porte le détail (longueur mesurée, nombre d'images
 * fautives…). Immuable ; sérialisé tel quel dans l'instantané JSONB du rapport.
 */
final readonly class Constat
{
    public function __construct(
        public string $regle,
        public Gravite $gravite,
        public string $message,
    ) {
    }

    public static function ok(string $regle, string $message = ''): self
    {
        return new self($regle, Gravite::Ok, $message);
    }

    public static function alerte(string $regle, string $message): self
    {
        return new self($regle, Gravite::Alerte, $message);
    }

    public static function erreur(string $regle, string $message): self
    {
        return new self($regle, Gravite::Erreur, $message);
    }
}
