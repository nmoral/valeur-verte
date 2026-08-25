<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Une règle SEO **transverse** (#345) : elle raisonne sur l'**ensemble** des pages
 * crawlées, pas sur une seule — unicité des titres, des descriptions, liens internes
 * cassés. Elle rend une liste de constats (un « tout va bien », ou un par anomalie).
 * Taguée `app.regle_seo_transverse` (autoconfiguration).
 */
#[AutoconfigureTag('app.regle_seo_transverse')]
interface RegleTransverse
{
    public function code(): string;

    /**
     * @param list<PageAuditee> $pages
     *
     * @return list<Constat>
     */
    public function evaluer(array $pages): array;
}
