<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Code HTTP de la réponse (#345). Une page indexable doit répondre `200`. Une
 * redirection (3xx) reste indexable mais dilue le signal et allonge le crawl →
 * alerte ; un 4xx/5xx est une page morte servie au moteur → erreur. Seule règle
 * on-page qui lit le **code de réponse** plutôt que le HTML.
 */
final class RegleCodeHttp implements RegleOnPage
{
    public function code(): string
    {
        return 'code-http';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $code = $page->codeHttp;

        if ($code >= 200 && $code < 300) {
            return Constat::ok($this->code(), \sprintf('Code HTTP %d.', $code));
        }

        if ($code >= 300 && $code < 400) {
            return Constat::alerte($this->code(), \sprintf('Redirection (code HTTP %d).', $code));
        }

        return Constat::erreur($this->code(), \sprintf('Code HTTP %d.', $code));
    }
}
