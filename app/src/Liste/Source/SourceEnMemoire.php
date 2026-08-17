<?php

declare(strict_types=1);

namespace App\Liste\Source;

use App\Liste\ColonnesTriables;
use App\Liste\Direction;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\SourceListe;

/**
 * Adaptateur pour une liste déjà matérialisée en mémoire (value-objects). Filtre,
 * trie puis découpe la collection. Convient aux listes de faible cardinalité
 * (comptes, candidatures, bouquets) ; les grosses volumétries passent par SourceDbal.
 *
 * @template T
 */
final class SourceEnMemoire implements SourceListe
{
    /**
     * @param list<T>                                                  $elements
     * @param array<string, callable(T): (int|float|string|bool|null)> $extracteursTri  clé de tri → extracteur
     * @param list<callable(T): string>                                $champsRecherche extracteurs de texte cherchable
     */
    public function __construct(
        private readonly array $elements,
        private readonly ColonnesTriables $colonnes,
        private readonly array $extracteursTri,
        private readonly array $champsRecherche = [],
    ) {
    }

    /**
     * @return PageListe<T>
     */
    public function interroger(ParametresListe $parametres): PageListe
    {
        $elements = $this->filtrer($this->elements, $parametres);
        $elements = $this->trier($elements, $parametres);

        return PageListe::decouperEnMemoire($elements, $parametres);
    }

    public function colonnes(): ColonnesTriables
    {
        return $this->colonnes;
    }

    /**
     * @param list<T> $elements
     *
     * @return list<T>
     */
    private function filtrer(array $elements, ParametresListe $parametres): array
    {
        if (!$parametres->aUneRecherche() || [] === $this->champsRecherche) {
            return $elements;
        }

        $aiguille = mb_strtolower($parametres->recherche());

        return array_values(array_filter($elements, function (mixed $element) use ($aiguille): bool {
            foreach ($this->champsRecherche as $champ) {
                if (str_contains(mb_strtolower($champ($element)), $aiguille)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param list<T> $elements
     *
     * @return list<T>
     */
    private function trier(array $elements, ParametresListe $parametres): array
    {
        $extracteur = $this->extracteursTri[$parametres->tri()] ?? null;
        if (null === $extracteur) {
            return $elements;
        }

        $sens = Direction::Desc === $parametres->direction() ? -1 : 1;
        usort(
            $elements,
            static fn (mixed $a, mixed $b): int => $sens * ($extracteur($a) <=> $extracteur($b)),
        );

        return $elements;
    }
}
