<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

/**
 * État d'indexation d'une URL (#346), tel que l'URL Inspection API le rend : l'URL
 * et son état de couverture (« Submitted and indexed », « Crawled - not indexed »…).
 */
final readonly class EtatIndexation
{
    public function __construct(
        public string $url,
        public string $couverture,
    ) {
    }
}
