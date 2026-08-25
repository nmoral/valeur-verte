<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Unicité des meta descriptions sur l'ensemble du périmètre (#345) — voir
 * {@see RegleUnicite}.
 */
final class RegleDescriptionsUniques extends RegleUnicite
{
    public function code(): string
    {
        return 'descriptions-uniques';
    }

    protected function libelle(): string
    {
        return 'description';
    }

    protected function valeur(PageAuditee $page): string
    {
        $balises = $page->dom()->filter('meta[name="description"]');

        return 0 === $balises->count() ? '' : ($balises->first()->attr('content') ?? '');
    }
}
