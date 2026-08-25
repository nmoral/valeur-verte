<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Données structurées JSON-LD (#345). schema.org (types `RealEstateAgent`, `Report`,
 * `BreadcrumbList`…) permet les résultats enrichis : absentes, on rate cette
 * visibilité (alerte) ; présentes mais **illisibles**, elles sont pire que rien —
 * le moteur les rejette et peut douter du reste (erreur) ; présentes et parsables
 * mais sans `@context`/`@type`, elles ne décrivent aucune entité exploitable (alerte).
 */
final class RegleDonneesStructurees implements RegleOnPage
{
    public function code(): string
    {
        return 'donnees-structurees';
    }

    public function evaluer(PageAuditee $page): Constat
    {
        $blocs = $page->dom()->filter('script[type="application/ld+json"]');
        if (0 === $blocs->count()) {
            return Constat::alerte($this->code(), 'Aucune donnée structurée JSON-LD.');
        }

        $exploitable = false;
        foreach ($blocs as $bloc) {
            $donnees = json_decode($bloc->textContent, true);
            if (null === $donnees && \JSON_ERROR_NONE !== json_last_error()) {
                return Constat::erreur($this->code(), 'Bloc JSON-LD illisible (JSON invalide).');
            }

            if (\is_array($donnees) && (isset($donnees['@context']) || isset($donnees['@type']))) {
                $exploitable = true;
            }
        }

        if (!$exploitable) {
            return Constat::alerte($this->code(), 'JSON-LD présent mais sans @context ni @type.');
        }

        return Constat::ok($this->code(), \sprintf('%d bloc(s) JSON-LD exploitable(s).', $blocs->count()));
    }
}
