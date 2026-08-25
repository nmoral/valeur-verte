<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\PageAuditee;

/**
 * Socle des règles d'unicité transverses (#345) : deux pages qui partagent le même
 * `<title>` (ou la même meta description) se cannibalisent en résultats de recherche —
 * le moteur ne sait laquelle privilégier. On regroupe les pages par valeur, on ignore
 * les valeurs vides (leur absence est déjà signalée par la règle on-page dédiée), et
 * on rend une seule alerte agrégée listant les groupes en doublon.
 */
abstract class RegleUnicite implements RegleTransverse
{
    /**
     * Libellé du champ contrôlé, au singulier (« titre », « description »).
     */
    abstract protected function libelle(): string;

    /**
     * Valeur à comparer entre pages (déjà brute ; le tri/vide est géré ici).
     */
    abstract protected function valeur(PageAuditee $page): string;

    public function evaluer(array $pages): array
    {
        /** @var array<string, list<string>> $urlsParValeur */
        $urlsParValeur = [];
        foreach ($pages as $page) {
            $valeur = trim($this->valeur($page));
            if ('' === $valeur) {
                continue;
            }
            $urlsParValeur[$valeur][] = $page->url;
        }

        $doublons = [];
        foreach ($urlsParValeur as $valeur => $urls) {
            if (\count($urls) > 1) {
                $doublons[] = \sprintf('« %s » (%d pages)', $valeur, \count($urls));
            }
        }

        if ([] === $doublons) {
            return [Constat::ok($this->code(), \sprintf('Aucun %s en doublon.', $this->libelle()))];
        }

        return [Constat::alerte($this->code(), \sprintf('%s en doublon : %s.', ucfirst($this->libelle()), implode(' ; ', $doublons)))];
    }
}
