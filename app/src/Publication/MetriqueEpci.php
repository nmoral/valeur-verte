<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Ce que le hub EPCI (#363) synthétise pour une intercommunalité : nombre de
 * communes mesurées, gain médian E→C, supplément médian du cran C→B (là où un B
 * est publié), et part des mailles estimées **au-delà du local** — cran EPCI ou
 * départemental de la cascade (#169), qui dilue la mesure communale.
 *
 * Analogue EPCI de {@see MetriqueDepartement}. Différence : le regroupement des
 * mailles est porté par le rattachement EPCI (``data.commune_epci``), pas par le
 * préfixe INSEE — un EPCI peut chevaucher deux départements. Et la « dilution »
 * pertinente est ``mode !== 'local'`` (EPCI comme départemental), là où le
 * département ne comptait que le cran départemental.
 */
final readonly class MetriqueEpci
{
    public function __construct(
        public string $codeEpci,
        public string $nom,
        public int $zones,
        public ?int $gainEc,
        public ?int $gainCb,
        public int $partNonLocale,
    ) {
    }

    /**
     * @param list<Maille> $mailles mailles publiées des communes de l'EPCI (au moins une)
     */
    public static function pour(string $codeEpci, string $nom, array $mailles): self
    {
        $nonLocales = array_filter($mailles, static fn (Maille $maille) => 'local' !== $maille->mode);

        return new self(
            codeEpci: $codeEpci,
            nom: $nom,
            zones: \count($mailles),
            gainEc: Mediane::de(self::gains($mailles, 'E', 'C')),
            gainCb: Mediane::de(self::gains($mailles, 'C', 'B')),
            partNonLocale: (int) round(100 * \count($nonLocales) / \count($mailles)),
        );
    }

    /**
     * @param list<Maille> $mailles
     *
     * @return list<int>
     */
    private static function gains(array $mailles, string $depuis, string $vers): array
    {
        $gains = [];
        foreach ($mailles as $maille) {
            $gain = $maille->gain($depuis, $vers);
            if (null !== $gain) {
                $gains[] = $gain;
            }
        }

        return $gains;
    }
}
