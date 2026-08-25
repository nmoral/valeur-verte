<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

/**
 * Résultat d'une collecte de présence externe (#346) : agrégats de recherche
 * (ou `null` si Search Console n'est pas branché), état d'indexation et Core Web
 * Vitals des URLs clés. `configure` dit si **au moins une** source a répondu — sinon
 * la présence externe n'est pas encore provisionnée (secrets absents au lancement).
 * Objet-valeur pur, figé ensuite dans un snapshot {@see RapportPresence}.
 */
final readonly class RapportPresenceExterne
{
    /**
     * @param list<EtatIndexation> $indexation
     * @param list<WebVitals>      $vitals
     */
    public function __construct(
        public bool $configure,
        public ?MetriquesRecherche $recherche,
        public array $indexation,
        public array $vitals,
    ) {
    }
}
