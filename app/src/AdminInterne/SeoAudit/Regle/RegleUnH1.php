<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Un seul `<h1>` par page (#345). Le `h1` annonce le sujet de la page ; aucun prive
 * le moteur de ce repère (erreur), plusieurs diluent le signal et trahissent souvent
 * un gabarit mal composé (alerte).
 */
final class RegleUnH1 implements RegleOnPage
{
    public function code(): string
    {
        return 'un-h1';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $nombre = $page->dom()->filter('h1')->count();

        if (0 === $nombre) {
            return Constat::erreur($this->code(), 'Aucun <h1> sur la page.');
        }

        if ($nombre > 1) {
            return Constat::alerte($this->code(), \sprintf('%d balises <h1> (une seule attendue).', $nombre));
        }

        return Constat::ok($this->code(), 'Un seul <h1>.');
    }
}
