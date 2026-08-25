<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

/**
 * Fournit le périmètre d'URLs à auditer (#345). Abstraction du {@see CollecteurUrls}
 * concret : l'orchestrateur en dépend, ce qui le rend testable sans router ni base.
 */
interface SourceUrls
{
    /**
     * @return list<string> URLs absolues à auditer
     */
    public function urls(): array;
}
