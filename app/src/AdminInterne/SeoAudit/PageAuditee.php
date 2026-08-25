<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Une page telle que le crawler l'a récupérée (#345) : son URL, le code HTTP de
 * la réponse et le corps HTML. Le `Crawler` DomCrawler est construit **une seule
 * fois à la demande** — l'analyse d'une page traverse plusieurs règles, inutile de
 * reparser le HTML à chaque fois. Le parsing est purement local (aucune I/O
 * réseau), ce qui garde le cœur des règles testable sans stack.
 */
final class PageAuditee
{
    private ?Crawler $dom = null;

    public function __construct(
        public readonly string $url,
        public readonly int $codeHttp,
        public readonly string $html,
    ) {
    }

    public function dom(): Crawler
    {
        return $this->dom ??= new Crawler($this->html, $this->url);
    }
}
