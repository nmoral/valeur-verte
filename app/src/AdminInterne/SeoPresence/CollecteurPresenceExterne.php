<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence;

use App\AdminInterne\SeoAudit\SourceUrls;
use Symfony\Component\Clock\ClockInterface;

/**
 * Orchestre la collecte de présence externe (#346) : agrégats Search Console sur les
 * 28 derniers jours, puis état d'indexation et Core Web Vitals des **URLs clés** —
 * le périmètre du sitemap ({@see SourceUrls}, #345), borné à `maxUrls` pour respecter
 * les quotas d'API. Chaque source se dégrade en `null`/liste vide si non configurée ;
 * `configure` vaut vrai dès qu'une source a répondu.
 */
final readonly class CollecteurPresenceExterne
{
    private const int FENETRE_JOURS = 28;

    public function __construct(
        private SearchConsole $searchConsole,
        private PageSpeed $pageSpeed,
        private SourceUrls $urls,
        private ClockInterface $horloge,
        private int $maxUrls = 10,
    ) {
    }

    public function collecter(): RapportPresenceExterne
    {
        $fin = $this->horloge->now();
        $debut = $fin->sub(new \DateInterval('P'.self::FENETRE_JOURS.'D'));
        $recherche = $this->searchConsole->metriques($debut, $fin);

        $urls = \array_slice($this->urls->urls(), 0, max(1, $this->maxUrls));

        $indexation = [];
        $vitals = [];
        foreach ($urls as $url) {
            $etat = $this->searchConsole->indexation($url);
            if (null !== $etat) {
                $indexation[] = $etat;
            }
            $vital = $this->pageSpeed->mesurer($url);
            if (null !== $vital) {
                $vitals[] = $vital;
            }
        }

        $configure = null !== $recherche || [] !== $indexation || [] !== $vitals;

        return new RapportPresenceExterne($configure, $recherche, $indexation, $vitals);
    }
}
