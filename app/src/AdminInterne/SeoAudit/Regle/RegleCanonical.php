<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * `<link rel="canonical">` présente, unique et renseignée (#345). La canonical dit
 * au moteur quelle URL fait foi et concentre le signal en cas de doublons (query
 * strings, sous-domaines). Absente ou multiple, le moteur choisit seul (alerte) ;
 * présente mais vide, elle **s'auto-référence à l'URL courante de façon ambiguë** →
 * erreur.
 */
final class RegleCanonical implements RegleOnPage
{
    public function code(): string
    {
        return 'canonical';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $liens = $page->dom()->filter('link[rel="canonical"]');
        $nombre = $liens->count();

        if (0 === $nombre) {
            return Constat::alerte($this->code(), 'Aucune balise canonical.');
        }

        if ($nombre > 1) {
            return Constat::alerte($this->code(), \sprintf('%d balises canonical (une seule attendue).', $nombre));
        }

        if ('' === trim($liens->first()->attr('href') ?? '')) {
            return Constat::erreur($this->code(), 'La balise canonical n\'a pas de href.');
        }

        return Constat::ok($this->code(), 'Canonical unique et renseignée.');
    }
}
