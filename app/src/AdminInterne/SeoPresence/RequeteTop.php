<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

/**
 * Une requête de recherche et ses volumes (#346) : le mot-clé tapé par l'internaute,
 * ses clics et impressions sur la période. Brique des « requêtes top » de la présence.
 */
final readonly class RequeteTop
{
    public function __construct(
        public string $requete,
        public int $clics,
        public int $impressions,
    ) {
    }
}
