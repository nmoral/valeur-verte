<?php

declare(strict_types=1);

namespace App\Liste;

/**
 * Paramètres d'affichage d'une liste (page, taille, tri, sens, recherche),
 * normalisés aux frontières : page ≥ 1, taille bornée (protège la volumétrie),
 * tri contraint à la liste blanche des colonnes triables (anti-injection ORDER BY),
 * sens borné à asc/desc, recherche nettoyée et tronquée.
 */
final readonly class ParametresListe
{
    public const PAR_PAGE_MAX = 200;
    public const RECHERCHE_MAX = 200;

    private function __construct(
        private int $page,
        private int $parPage,
        private string $tri,
        private Direction $direction,
        private string $recherche,
    ) {
    }

    public static function depuis(
        int $page,
        int $parPage,
        ?string $tri,
        string $direction,
        string $recherche,
        ColonnesTriables $triables,
    ): self {
        return new self(
            max(1, $page),
            max(1, min($parPage, self::PAR_PAGE_MAX)),
            $triables->normaliser($tri),
            Direction::depuis($direction),
            mb_substr(trim($recherche), 0, self::RECHERCHE_MAX),
        );
    }

    public function page(): int
    {
        return $this->page;
    }

    public function parPage(): int
    {
        return $this->parPage;
    }

    public function tri(): string
    {
        return $this->tri;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    public function recherche(): string
    {
        return $this->recherche;
    }

    public function aUneRecherche(): bool
    {
        return '' !== $this->recherche;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->parPage;
    }
}
