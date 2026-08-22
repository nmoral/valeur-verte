<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Produit le jeton opaque qui sert d'adresse à une page publique (#38, épic #6) :
 * il tire au hasard, et retire tant que le jeton tiré est déjà pris.
 *
 * La collision est improbable (128 bits) mais pas impossible ; la laisser filer
 * jusqu'à l'insertion la ferait trancher par la contrainte d'unicité de la base,
 * c'est-à-dire par une erreur 500 au visage du pro. On préfère un nouveau tirage.
 * Après {@see self::ESSAIS_MAX} échecs, on renonce bruyamment plutôt que de
 * boucler : à ce stade, ce n'est plus de la malchance, c'est une panne.
 */
final readonly class GenerateurJetonPagePublique
{
    private const int ESSAIS_MAX = 5;

    public function __construct(
        private PagePubliqueRepository $pages,
        private TirageJeton $tirage,
    ) {
    }

    public function nouveau(): string
    {
        for ($essai = 1; $essai <= self::ESSAIS_MAX; ++$essai) {
            $jeton = $this->tirage->tirer();
            if (!$this->pages->jetonDejaPris($jeton)) {
                return $jeton;
            }
        }

        throw new \RuntimeException(\sprintf('Aucun jeton de page publique libre après %d tirages : le tirage aléatoire est probablement en panne.', self::ESSAIS_MAX));
    }
}
