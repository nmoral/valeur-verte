<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Les classements chiffrés de la page publique (#48/#224), dérivés des métriques
 * départementales du millésime publié — plus aucun département ni pourcentage
 * figé dans le gabarit.
 *
 * Les listes citent les départements extrêmes par gain E→C, groupés par valeur
 * égale, en accumulant les paliers jusqu'à en nommer au moins cinq.
 */
final readonly class Constats
{
    private const AU_MOINS = 5;
    private const SEUIL_ELEVE = 30;

    /**
     * @param list<GroupeGain> $gainsLesPlusFaibles
     * @param list<GroupeGain> $gainsLesPlusForts
     */
    public function __construct(
        public array $gainsLesPlusFaibles,
        public array $gainsLesPlusForts,
        public int $departementsAuDela30,
    ) {
    }

    /**
     * @param list<MetriqueDepartement> $metriques
     */
    public static function depuis(array $metriques): self
    {
        $gains = [];
        foreach ($metriques as $metrique) {
            if (null === $metrique->gainEc) {
                continue;
            }
            $gains[] = ['nom' => $metrique->nom, 'valeur' => $metrique->gainEc];
        }

        return new self(
            self::grouper(self::tries($gains, decroissant: false)),
            self::grouper(self::tries($gains, decroissant: true)),
            \count(array_filter($gains, static fn (array $g): bool => $g['valeur'] > self::SEUIL_ELEVE)),
        );
    }

    /**
     * @param list<array{nom: string, valeur: int}> $items
     *
     * @return list<array{nom: string, valeur: int}>
     */
    private static function tries(array $items, bool $decroissant): array
    {
        usort($items, static fn (array $a, array $b): int => $decroissant
            ? $b['valeur'] <=> $a['valeur']
            : $a['valeur'] <=> $b['valeur']);

        return $items;
    }

    /**
     * Regroupe les items (déjà triés) par valeur égale, en s'arrêtant après le
     * palier qui porte le total nommé à au moins cinq.
     *
     * @param list<array{nom: string, valeur: int}> $items
     *
     * @return list<GroupeGain>
     */
    private static function grouper(array $items): array
    {
        $groupes = [];
        $nommes = 0;
        $i = 0;
        $total = \count($items);
        while ($i < $total && $nommes < self::AU_MOINS) {
            $valeur = $items[$i]['valeur'];
            $noms = [];
            while ($i < $total && $items[$i]['valeur'] === $valeur) {
                $noms[] = $items[$i]['nom'];
                ++$i;
            }
            $groupes[] = new GroupeGain($valeur, $noms);
            $nommes += \count($noms);
        }

        return $groupes;
    }
}
