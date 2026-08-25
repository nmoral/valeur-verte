<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Open Graph et Twitter Card (#345) : `og:title`, `og:type`, `twitter:card`. Elles
 * pilotent l'aperçu au partage (réseaux sociaux, messageries). Manquantes, le lien
 * partagé s'affiche nu — sans conséquence d'indexation → alerte, jamais une erreur.
 * Un `content` vide vaut absence.
 */
final class RegleOpenGraph implements RegleOnPage
{
    public function code(): string
    {
        return 'open-graph';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $dom = $page->dom();
        $manquantes = [];
        foreach (['og:title' => 'property', 'og:type' => 'property', 'twitter:card' => 'name'] as $balise => $attribut) {
            $trouvees = $dom->filter(\sprintf('meta[%s="%s"]', $attribut, $balise));
            if (0 === $trouvees->count() || '' === trim($trouvees->first()->attr('content') ?? '')) {
                $manquantes[] = $balise;
            }
        }

        if ([] !== $manquantes) {
            return Constat::alerte($this->code(), 'Balise(s) de partage manquante(s) : '.implode(', ', $manquantes).'.');
        }

        return Constat::ok($this->code(), 'Open Graph et Twitter Card présents.');
    }
}
