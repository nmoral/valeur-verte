<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

/**
 * Port de mesure des Core Web Vitals d'une URL (#346). Rend `null` quand la source
 * n'est pas configurée ou indisponible — la présence externe est optionnelle et ne
 * doit jamais faire échouer la collecte.
 */
interface PageSpeed
{
    public function mesurer(string $url): ?WebVitals;
}
