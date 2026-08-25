<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

/**
 * Résultat de l'audit d'une page (#345) : son URL, le code HTTP observé et la liste
 * des constats on-page (un par règle). Immuable ; sérialisé dans l'instantané JSONB.
 */
final readonly class ResultatPage
{
    /**
     * @param list<Constat> $constats
     */
    public function __construct(
        public string $url,
        public int $codeHttp,
        public array $constats,
    ) {
    }
}
