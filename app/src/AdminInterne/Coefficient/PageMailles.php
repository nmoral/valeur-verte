<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

/**
 * Une page de mailles supervisées (#45) : la liste triée est découpée pour
 * l'affichage (1232 mailles ne se rendent pas d'un bloc), les signalées restant
 * globalement en tête puisque le tri précède le découpage.
 */
final readonly class PageMailles
{
    /**
     * @param list<MailleSupervisee> $mailles
     */
    private function __construct(
        private array $mailles,
        private int $page,
        private int $pages,
        private int $total,
    ) {
    }

    /**
     * @param list<MailleSupervisee> $toutes
     */
    public static function decouper(array $toutes, int $page, int $taille): self
    {
        $total = \count($toutes);
        $pages = max(1, (int) ceil($total / $taille));
        $page = max(1, min($page, $pages));

        $mailles = array_values(\array_slice($toutes, ($page - 1) * $taille, $taille));

        return new self($mailles, $page, $pages, $total);
    }

    /**
     * @return list<MailleSupervisee>
     */
    public function mailles(): array
    {
        return $this->mailles;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function total(): int
    {
        return $this->total;
    }
}
