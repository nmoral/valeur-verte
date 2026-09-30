<?php

declare(strict_types=1);

namespace App\PourquoiIci;

/**
 * Le moteur de narration du bloc « Pourquoi ici ? » (#341, Lot 3).
 *
 * À partir des marqueurs d'une zone et de la mesure (gain E→C, nombre de ventes),
 * il rend la liste ordonnée des phrases : d'abord le FAIT mesuré (sans badge),
 * puis les HYPOTHÈSES de la liste blanche dont le contexte est réuni ET cohérent
 * avec la mesure (cf. {@see RegleContextuelle}). La mise en forme du badge
 * « Hypothèse » et l'insertion dans la page relèvent du Lot 4.
 */
final readonly class MoteurPourquoiIci
{
    /**
     * @param list<RegleContextuelle> $listeBlanche
     */
    public function __construct(private array $listeBlanche)
    {
    }

    /**
     * @return list<Phrase>
     */
    public function phrases(Marqueurs $marqueurs, ?int $gainEc, int $n): array
    {
        $phrases = [];

        if (null !== $gainEc) {
            $phrases[] = Phrase::mesure(\sprintf(
                'Ici, rénover une maison de E à C ajoute en moyenne +%d %% à sa valeur '
                .'(mesuré sur %d ventes).',
                $gainEc,
                $n,
            ));
        }

        foreach ($this->listeBlanche as $regle) {
            if ($regle->sApplique($marqueurs)) {
                $phrases[] = Phrase::hypothese($regle->texte);
            }
        }

        return $phrases;
    }
}
