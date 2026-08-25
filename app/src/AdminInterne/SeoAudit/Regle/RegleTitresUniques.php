<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Unicité des `<title>` sur l'ensemble du périmètre (#345) — voir {@see RegleUnicite}.
 */
final class RegleTitresUniques extends RegleUnicite
{
    public function code(): string
    {
        return 'titres-uniques';
    }

    protected function libelle(): string
    {
        return 'titre';
    }

    protected function valeur(PageAuditee $page): string
    {
        $titres = $page->dom()->filter('title');

        return 0 === $titres->count() ? '' : $titres->first()->text();
    }
}
