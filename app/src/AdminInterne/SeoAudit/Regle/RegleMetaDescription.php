<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * `<meta name="description">` : présente, non vide, de longueur raisonnable (#345).
 * La description n'influe pas directement sur le classement mais **c'est le snippet**
 * affiché en résultat : absente, le moteur en fabrique un depuis le corps (souvent
 * médiocre), d'où l'erreur ; hors bornes, il est tronqué → alerte.
 */
final class RegleMetaDescription implements RegleOnPage
{
    public const int LONGUEUR_MIN = 50;
    public const int LONGUEUR_MAX = 160;

    public function code(): string
    {
        return 'meta-description';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $balises = $page->dom()->filter('meta[name="description"]');
        if (0 === $balises->count()) {
            return Constat::erreur($this->code(), 'Aucune meta description.');
        }

        $description = trim($balises->first()->attr('content') ?? '');
        if ('' === $description) {
            return Constat::erreur($this->code(), 'La meta description est vide.');
        }

        $longueur = mb_strlen($description);
        if ($longueur < self::LONGUEUR_MIN) {
            return Constat::alerte($this->code(), \sprintf('Description trop courte (%d caractères, minimum %d).', $longueur, self::LONGUEUR_MIN));
        }

        if ($longueur > self::LONGUEUR_MAX) {
            return Constat::alerte($this->code(), \sprintf('Description trop longue (%d caractères, maximum %d).', $longueur, self::LONGUEUR_MAX));
        }

        return Constat::ok($this->code(), \sprintf('Description de %d caractères.', $longueur));
    }
}
