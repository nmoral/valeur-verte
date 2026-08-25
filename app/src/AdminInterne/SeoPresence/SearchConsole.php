<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

/**
 * Port Search Console (#346) : agrégats de recherche sur une période et état
 * d'indexation d'une URL. Rend `null` quand la source n'est pas configurée ou
 * indisponible — présence externe optionnelle, jamais bloquante.
 */
interface SearchConsole
{
    public function metriques(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $maxRequetes = 10): ?MetriquesRecherche;

    public function indexation(string $url): ?EtatIndexation;
}
