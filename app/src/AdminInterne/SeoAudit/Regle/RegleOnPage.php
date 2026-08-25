<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Une règle SEO « on-page » (#345) : elle regarde **une seule page** — son HTML
 * et son code HTTP — et rend un {@see Constat}. Sans état, sans I/O réseau : le
 * cœur testable de l'audit. Les implémentations sont taguées `app.regle_seo_on_page`
 * (autoconfiguration) et injectées en lot dans l'auditeur.
 */
interface RegleOnPage
{
    /**
     * Code stable de la règle (kebab-case) — clé de regroupement dans le rapport.
     */
    public function code(): string;

    public function evaluer(PageAuditee $page): Constat;
}
