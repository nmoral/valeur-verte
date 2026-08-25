<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Attribut `alt` sur les images (#345) : accessibilité et référencement image. Une
 * image **sans attribut alt du tout** est fautive (alerte) ; un `alt=""` explicite
 * est légitime (image décorative) et n'est donc pas compté. On signale, on ne bloque
 * pas : l'absence d'alt ne déréférence pas la page.
 */
final class RegleAltImages implements RegleOnPage
{
    public function code(): string
    {
        return 'alt-images';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $images = $page->dom()->filter('img');
        if (0 === $images->count()) {
            return Constat::ok($this->code(), 'Aucune image.');
        }

        $sansAlt = 0;
        foreach ($images as $image) {
            if ($image instanceof \DOMElement && !$image->hasAttribute('alt')) {
                ++$sansAlt;
            }
        }

        if ($sansAlt > 0) {
            return Constat::alerte($this->code(), \sprintf('%d image(s) sans attribut alt (sur %d).', $sansAlt, $images->count()));
        }

        return Constat::ok($this->code(), \sprintf('Les %d image(s) portent un attribut alt.', $images->count()));
    }
}
