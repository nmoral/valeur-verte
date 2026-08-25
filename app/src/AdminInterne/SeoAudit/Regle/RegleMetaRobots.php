<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * `<meta name="robots">` : la directive d'indexation (#345). L'audit ne porte que
 * sur des **pages publiques indexables** — un `noindex` y est presque toujours une
 * erreur de gabarit qui retire silencieusement la page des résultats, d'où l'erreur.
 * L'absence de directive vaut « index » par défaut : rien à signaler.
 */
final class RegleMetaRobots implements RegleOnPage
{
    public function code(): string
    {
        return 'meta-robots';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $balises = $page->dom()->filter('meta[name="robots"]');
        if (0 === $balises->count()) {
            return Constat::ok($this->code(), 'Aucune directive robots (indexable par défaut).');
        }

        $contenu = mb_strtolower(trim($balises->first()->attr('content') ?? ''));
        if (str_contains($contenu, 'noindex')) {
            return Constat::erreur($this->code(), 'La page est en noindex.');
        }

        return Constat::ok($this->code(), \sprintf('Directive robots : « %s ».', $contenu));
    }
}
