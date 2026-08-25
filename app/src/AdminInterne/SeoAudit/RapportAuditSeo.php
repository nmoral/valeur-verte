<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

/**
 * Résultat complet d'un audit SEO (#345) : les constats page par page plus les
 * constats **globaux** (règles transverses au site — unicité des titres, liens
 * cassés — et vérifications robots.txt / sitemap.xml). Objet-valeur pur ; c'est lui
 * qu'on fige dans l'instantané JSONB persisté ({@see RapportSeo}).
 */
final readonly class RapportAuditSeo
{
    /**
     * @param list<ResultatPage> $pages
     * @param list<Constat>      $constatsGlobaux
     */
    public function __construct(
        public array $pages,
        public array $constatsGlobaux,
    ) {
    }

    public function nombreUrls(): int
    {
        return \count($this->pages);
    }

    public function compte(Gravite $gravite): int
    {
        $total = 0;
        foreach ($this->tousLesConstats() as $constat) {
            if ($constat->gravite === $gravite) {
                ++$total;
            }
        }

        return $total;
    }

    /**
     * Score technique sur 100 (#345) : 100 quand tout est vert, décroît avec la somme
     * des pénalités rapportée au pire cas possible (chaque constat en erreur). Un audit
     * sans aucun constat vaut 100 (rien à reprocher).
     */
    public function score(): int
    {
        $penalites = 0;
        $constats = $this->tousLesConstats();
        foreach ($constats as $constat) {
            $penalites += $constat->gravite->penalite();
        }

        $maxPenalites = \count($constats) * Gravite::Erreur->penalite();
        if (0 === $maxPenalites) {
            return 100;
        }

        return (int) round(100 * (1 - $penalites / $maxPenalites));
    }

    /**
     * @return list<Constat>
     */
    private function tousLesConstats(): array
    {
        $constats = $this->constatsGlobaux;
        foreach ($this->pages as $page) {
            foreach ($page->constats as $constat) {
                $constats[] = $constat;
            }
        }

        return $constats;
    }
}
