<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * `<title>` : présent, non vide, de longueur raisonnable (#345). Le titre est le
 * premier signal de pertinence pour un moteur et le libellé affiché en résultat ;
 * absent, il est **remplacé par le moteur** (souvent mal), d'où l'erreur. Hors des
 * bornes, il est tronqué en SERP sans casser l'indexation → simple alerte.
 */
final class RegleTitre implements RegleOnPage
{
    public const int LONGUEUR_MIN = 10;
    public const int LONGUEUR_MAX = 60;

    public function code(): string
    {
        return 'titre';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $titres = $page->dom()->filter('title');
        if (0 === $titres->count()) {
            return Constat::erreur($this->code(), 'Aucune balise <title>.');
        }

        $titre = trim($titres->first()->text());
        if ('' === $titre) {
            return Constat::erreur($this->code(), 'La balise <title> est vide.');
        }

        $longueur = mb_strlen($titre);
        if ($longueur < self::LONGUEUR_MIN) {
            return Constat::alerte($this->code(), \sprintf('Titre trop court (%d caractères, minimum %d).', $longueur, self::LONGUEUR_MIN));
        }

        if ($longueur > self::LONGUEUR_MAX) {
            return Constat::alerte($this->code(), \sprintf('Titre trop long (%d caractères, maximum %d).', $longueur, self::LONGUEUR_MAX));
        }

        return Constat::ok($this->code(), \sprintf('Titre de %d caractères.', $longueur));
    }
}
