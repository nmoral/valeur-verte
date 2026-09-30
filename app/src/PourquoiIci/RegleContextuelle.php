<?php

declare(strict_types=1);

namespace App\PourquoiIci;

/**
 * Une règle de la liste blanche du bloc « Pourquoi ici ? » (#341, Lot 3).
 *
 * Génération **non libre** : une phrase contextuelle n'existe que portée par une
 * règle indexée sur des conditions de marqueurs. Une règle réunit :
 * - `contexte` : les marqueurs contextuels qui la déclenchent (climat, marché…) ;
 * - `coherence` : la garde sur le marqueur mesuré (`levier_renovation`) — la règle
 *   n'est rendue que si la mesure pointe dans le même sens que le contexte.
 *
 * Une condition est un couple `nom => valeurs acceptées` ; elle tient si la valeur
 * du marqueur figure parmi les valeurs acceptées. Toutes les conditions (contexte
 * ET cohérence) doivent tenir. Une cohérence vide = pas de garde (rare).
 */
final readonly class RegleContextuelle
{
    /**
     * @param array<string, list<string>> $contexte  nom marqueur → valeurs déclenchantes
     * @param array<string, list<string>> $coherence nom marqueur mesuré → valeurs requises
     */
    private function __construct(
        private array $contexte,
        private array $coherence,
        public string $texte,
    ) {
    }

    /**
     * @param array<string, list<string>> $contexte
     * @param array<string, list<string>> $coherence
     */
    public static function depuis(array $contexte, array $coherence, string $texte): self
    {
        return new self($contexte, $coherence, $texte);
    }

    public function sApplique(Marqueurs $marqueurs): bool
    {
        return $this->conditionsTiennent($this->contexte, $marqueurs)
            && $this->conditionsTiennent($this->coherence, $marqueurs);
    }

    /**
     * @param array<string, list<string>> $conditions
     */
    private function conditionsTiennent(array $conditions, Marqueurs $marqueurs): bool
    {
        foreach ($conditions as $nom => $valeursAcceptees) {
            $valeur = $marqueurs->valeur($nom);
            if (null === $valeur || !\in_array($valeur, $valeursAcceptees, true)) {
                return false;
            }
        }

        return true;
    }
}
