<?php

declare(strict_types=1);

namespace App\Liste;

/**
 * Une page de résultats d'une liste (tranche + métadonnées de pagination).
 * Généralise l'ancien PageMailles : `de()` ne découpe pas (les éléments sont
 * déjà la tranche renvoyée par un LIMIT/OFFSET SQL, le total vient d'un COUNT),
 * tandis que `decouperEnMemoire()` reproduit le découpage sur une liste complète.
 *
 * @template-covariant T
 */
final readonly class PageListe
{
    /**
     * @param list<T> $elements
     */
    private function __construct(
        private array $elements,
        private int $page,
        private int $parPage,
        private int $pages,
        private int $total,
    ) {
    }

    /**
     * @template U
     *
     * @param list<U> $elements
     *
     * @return self<U>
     */
    public static function de(array $elements, int $page, int $parPage, int $total): self
    {
        $total = max(0, $total);
        $parPage = max(1, $parPage);
        $pages = max(1, (int) ceil($total / $parPage));
        $page = max(1, min($page, $pages));

        return new self($elements, $page, $parPage, $pages, $total);
    }

    /**
     * @template U
     *
     * @param list<U> $tous
     *
     * @return self<U>
     */
    public static function decouperEnMemoire(array $tous, ParametresListe $parametres): self
    {
        $total = \count($tous);
        $parPage = $parametres->parPage();
        $pages = max(1, (int) ceil($total / $parPage));
        $page = max(1, min($parametres->page(), $pages));

        $tranche = \array_slice($tous, ($page - 1) * $parPage, $parPage);

        return self::de($tranche, $page, $parPage, $total);
    }

    /**
     * @return list<T>
     */
    public function elements(): array
    {
        return $this->elements;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function parPage(): int
    {
        return $this->parPage;
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function estVide(): bool
    {
        return 0 === $this->total;
    }

    public function pagePrecedente(): ?int
    {
        return $this->page > 1 ? $this->page - 1 : null;
    }

    public function pageSuivante(): ?int
    {
        return $this->page < $this->pages ? $this->page + 1 : null;
    }
}
