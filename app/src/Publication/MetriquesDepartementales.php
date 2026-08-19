<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Les mailles publiées agrégées par département — la lecture nationale du modèle.
 *
 * Collection plutôt que tableau associatif : les codes de département sont des
 * chaînes (« 09 », « 2A »), et PHP transformerait « 31 » en clé entière.
 */
final readonly class MetriquesDepartementales
{
    /**
     * @param list<MetriqueDepartement> $metriques triées par code de département
     */
    private function __construct(private array $metriques)
    {
    }

    /**
     * @param list<Maille> $mailles
     */
    public static function depuisMailles(array $mailles): self
    {
        $parDepartement = [];
        foreach ($mailles as $maille) {
            $parDepartement[$maille->departement()][] = $maille;
        }
        ksort($parDepartement);

        $metriques = [];
        foreach ($parDepartement as $code => $duDepartement) {
            $metriques[] = MetriqueDepartement::pour((string) $code, $duDepartement);
        }

        return new self($metriques);
    }

    /**
     * @return list<MetriqueDepartement>
     */
    public function departements(): array
    {
        return $this->metriques;
    }

    public function pour(string $code): ?MetriqueDepartement
    {
        foreach ($this->metriques as $metrique) {
            if ($metrique->code === $code) {
                return $metrique;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (MetriqueDepartement $metrique) => $metrique->code, $this->metriques);
    }

    public function departementsCouverts(): int
    {
        return \count($this->metriques);
    }

    public function zonesMesurees(): int
    {
        return array_sum(array_map(static fn (MetriqueDepartement $metrique) => $metrique->zones, $this->metriques));
    }

    /**
     * Médiane nationale des médianes départementales : le chiffre du bandeau de
     * preuve pèse chaque département pareil, pas chaque commune.
     */
    public function medianeGainEc(): ?int
    {
        return Mediane::de($this->gains(static fn (MetriqueDepartement $metrique) => $metrique->gainEc));
    }

    /**
     * Supplément national du cran C→B : médiane des médianes départementales, sur
     * les seuls départements où un B est publié.
     */
    public function medianeGainCb(): ?int
    {
        return Mediane::de($this->gains(static fn (MetriqueDepartement $metrique) => $metrique->gainCb));
    }

    /**
     * Nombre de départements où le cran C→B est mesurable (au moins une maille
     * portant un B publié).
     */
    public function departementsAvecCb(): int
    {
        return \count($this->gains(static fn (MetriqueDepartement $metrique) => $metrique->gainCb));
    }

    /**
     * @param callable(MetriqueDepartement): ?int $gain
     *
     * @return list<int>
     */
    private function gains(callable $gain): array
    {
        $gains = [];
        foreach ($this->metriques as $metrique) {
            $valeur = $gain($metrique);
            if (null !== $valeur) {
                $gains[] = $valeur;
            }
        }

        return $gains;
    }
}
