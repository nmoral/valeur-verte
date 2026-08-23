<?php

declare(strict_types=1);

namespace App\AdminInterne\Ingestion;

/**
 * Synthèse de l'état des imports (#46) : l'en-tête de l'écran, au-dessus du tableau
 * par département. Elle ne mesure rien de neuf — elle agrège les dernières
 * ingestions archivées (#131) pour répondre aux trois questions qu'on se pose en
 * arrivant : depuis quand la base n'a-t-elle pas bougé, qu'est-ce qui est couvert,
 * et qu'est-ce qui demande un regard.
 */
final readonly class SyntheseIngestion
{
    /**
     * @param list<EtatDernierImport> $departements rangés par code de département
     */
    private function __construct(
        private array $departements,
    ) {
    }

    /**
     * @param list<EtatDernierImport> $departements
     */
    public static function depuis(array $departements): self
    {
        usort(
            $departements,
            static fn (EtatDernierImport $a, EtatDernierImport $b): int => $a->departement() <=> $b->departement(),
        );

        return new self($departements);
    }

    /**
     * @return list<EtatDernierImport>
     */
    public function departements(): array
    {
        return $this->departements;
    }

    public function departementsCouverts(): int
    {
        return \count($this->departements);
    }

    /**
     * Date du dernier import, toutes régions confondues — nulle tant qu'aucun
     * département n'a été ingéré.
     */
    public function dernierImport(): ?\DateTimeImmutable
    {
        $dates = array_map(static fn (EtatDernierImport $e): \DateTimeImmutable => $e->importeLe(), $this->departements);

        return [] === $dates ? null : max($dates);
    }

    public function mutationsInserees(): int
    {
        return array_sum(array_map(static fn (EtatDernierImport $e): int => $e->mutationsInserees(), $this->departements));
    }

    public function ventesAppariees(): int
    {
        return array_sum(array_map(static fn (EtatDernierImport $e): int => $e->ventesAppariees(), $this->departements));
    }

    /**
     * Taux d'appariement de la base : **pondéré par le volume**, jamais une moyenne
     * des taux départementaux — un département minuscule bien apparié ne compense
     * pas un gros département effondré.
     */
    public function tauxAppariement(): ?float
    {
        $inserees = $this->mutationsInserees();

        return 0 === $inserees ? null : $this->ventesAppariees() / $inserees;
    }

    public function communesEnEchec(): int
    {
        return array_sum(array_map(
            static fn (EtatDernierImport $e): int => \count($e->communesEnEchec()),
            $this->departements,
        ));
    }

    /**
     * @return list<EtatDernierImport>
     */
    public function departementsEnAlerte(\DateTimeImmutable $maintenant): array
    {
        return array_values(array_filter(
            $this->departements,
            static fn (EtatDernierImport $e): bool => $e->enAlerte($maintenant),
        ));
    }
}
