<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

/**
 * Core Web Vitals d'une URL (#346), tels que PageSpeed Insights les rend. Chaque
 * mesure est **optionnelle** : une métrique absente de la réponse reste `null`
 * plutôt que d'inventer une valeur. LCP/INP en millisecondes, CLS sans unité,
 * score de performance sur 100.
 */
final readonly class WebVitals
{
    public function __construct(
        public string $url,
        public ?int $lcpMs,
        public ?float $cls,
        public ?int $inpMs,
        public ?int $scorePerformance,
    ) {
    }
}
