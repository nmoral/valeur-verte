<?php

declare(strict_types=1);

namespace App\PourquoiIci;

/**
 * La liste blanche des hypothèses contextuelles du bloc « Pourquoi ici ? » (#341, Lot 3).
 *
 * Source unique et **révisable** des phrases contextuelles : aucune génération hors
 * de cette liste. Chaque règle porte le contexte qui la déclenche, la garde de
 * cohérence sur le marqueur mesuré `levier_renovation` (l'hypothèse n'est rendue
 * que si la mesure pointe dans le même sens), et un texte **prudent, au
 * conditionnel, jamais chiffré**. Les valeurs de marqueurs (`froid`, `detendu`,
 * `rural`…) sont celles écrites par le pipeline dans `data.marqueurs`.
 */
final readonly class ListeBlanche
{
    /**
     * @return list<RegleContextuelle>
     */
    public static function reglesParDefaut(): array
    {
        return [
            RegleContextuelle::depuis(
                contexte: ['climat' => ['froid']],
                coherence: ['levier_renovation' => ['moyen', 'fort']],
                texte: 'Département froid (zone H1) : le chauffage y pèse lourd, une maison bien isolée s\'y valorise probablement davantage.',
            ),
            RegleContextuelle::depuis(
                contexte: ['climat' => ['doux'], 'marche' => ['tendu']],
                coherence: ['levier_renovation' => ['faible']],
                texte: 'Climat doux et marché tendu : ici une passoire se vend malgré tout, le DPE pèse souvent moins sur le prix.',
            ),
            RegleContextuelle::depuis(
                contexte: ['marche' => ['detendu']],
                coherence: ['levier_renovation' => ['moyen', 'fort']],
                texte: 'Marché détendu : les acheteurs peuvent trier, l\'étiquette devient souvent un vrai levier de négociation.',
            ),
            RegleContextuelle::depuis(
                contexte: ['densite' => ['rural']],
                coherence: ['levier_renovation' => ['moyen', 'fort']],
                texte: 'Territoire rural, parc souvent ancien : une rénovation s\'y remarque probablement davantage.',
            ),
            RegleContextuelle::depuis(
                contexte: ['littoral' => ['oui'], 'tourisme' => ['fort']],
                coherence: ['levier_renovation' => ['faible']],
                texte: 'Littoral prisé : la demande soutient les prix, l\'impact du DPE y est généralement plus faible.',
            ),
        ];
    }
}
