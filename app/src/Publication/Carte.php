<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * La choroplèthe de la page (#224) : contours figés + métriques du millésime
 * publié, dans la structure que le script de carte consomme telle quelle.
 *
 * Un département sans métrique (Alsace-Moselle hors périmètre DVF, Corse et Paris
 * trop peu de ventes de maisons) est **dessiné quand même**, sans chiffres : une
 * carte trouée ferait croire à une panne.
 */
final readonly class Carte
{
    public const LARGEUR = 760;
    public const HAUTEUR = 720;

    /**
     * @param array<int|string, string> $chemins code de département → chemin SVG
     */
    public function __construct(
        private array $chemins,
        private MetriquesDepartementales $metriques,
    ) {
    }

    /**
     * @return array{w: int, h: int, feats: list<array{dept: string, nom: string, path: string, m: array{n: int, gain: ?int, gainED: ?int, dept: int}|null}>}
     */
    public function donnees(): array
    {
        $feats = [];
        foreach ($this->chemins as $code => $chemin) {
            $departement = (string) $code;
            $metrique = $this->metriques->pour($departement);

            $feats[] = [
                'dept' => $departement,
                'nom' => NomsDepartements::pour($departement),
                'path' => $chemin,
                'm' => null === $metrique ? null : [
                    'n' => $metrique->zones,
                    'gain' => $metrique->gainEc,
                    'gainED' => $metrique->gainEd,
                    'dept' => $metrique->partDepartementale,
                ],
            ];
        }

        return ['w' => self::LARGEUR, 'h' => self::HAUTEUR, 'feats' => $feats];
    }
}
