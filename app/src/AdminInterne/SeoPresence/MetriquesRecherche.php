<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

/**
 * Agrégats Search Console sur une période (#346) : clics, impressions, CTR et
 * position moyenne (pondérée par impressions) de la propriété, plus les requêtes
 * les plus cliquées. C'est la « présence réelle » côté recherche.
 */
final readonly class MetriquesRecherche
{
    /**
     * @param list<RequeteTop> $topRequetes
     */
    public function __construct(
        public int $clics,
        public int $impressions,
        public float $ctr,
        public float $positionMoyenne,
        public array $topRequetes,
    ) {
    }
}
