<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Ce que la carte colore pour un département (#224) : effectif de zones mesurées,
 * gains médians E→C et E→D, et part des zones estimées à l'échelle du département
 * (faute de ventes assez proches — cascade #169).
 */
final readonly class MetriqueDepartement
{
    public function __construct(
        public string $code,
        public string $nom,
        public int $zones,
        public ?int $gainEc,
        public ?int $gainEd,
        public int $partDepartementale,
    ) {
    }

    /**
     * @param list<Maille> $mailles mailles publiées du seul département
     */
    public static function pour(string $code, array $mailles): self
    {
        $departementales = array_filter($mailles, static fn (Maille $maille) => $maille->estDepartementale());

        return new self(
            code: $code,
            nom: NomsDepartements::pour($code),
            zones: \count($mailles),
            gainEc: Mediane::de(self::gains($mailles, 'E', 'C')),
            gainEd: Mediane::de(self::gains($mailles, 'E', 'D')),
            partDepartementale: (int) round(100 * \count($departementales) / \count($mailles)),
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
