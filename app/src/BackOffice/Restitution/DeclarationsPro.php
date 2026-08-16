<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\GrilleCout\Montant;

/**
 * Ce que le professionnel déclare sur la restitution (#26) : la situation de revenus
 * du ménage et ses retouches de chiffrage, poste par poste.
 *
 * Ces déclarations vivent **hors de l'instantané figé**, comme l'ajustement (#30).
 * Elles n'altèrent ni la mesure statistique ni le chiffrage dérivé de la grille :
 * le reste à charge se recalcule à l'affichage à partir des trois. Le pro peut donc
 * revenir dessus sans réémettre de rapport, et on garde de quoi montrer l'écart
 * entre « ce que votre grille disait » et « ce que vous avez saisi ».
 *
 * La catégorie est **déclarative** : les plafonds officiels dépendent du nombre de
 * personnes du foyer et de la région, données que le produit ne collecte pas. Elle
 * ne préjuge d'aucune éligibilité instruite par l'Anah.
 */
final readonly class DeclarationsPro
{
    /**
     * @param array<string, int> $retouchesChiffrageEuros indexé par Poste->name
     */
    private function __construct(
        private CategorieRevenu $categorieRevenu,
        private array $retouchesChiffrageEuros,
    ) {
    }

    public static function aucune(): self
    {
        return new self(CategorieRevenu::NonPrecisee, []);
    }

    /**
     * @param array<string, int> $retouchesChiffrageEuros indexé par Poste->name
     */
    public static function depuis(CategorieRevenu $categorieRevenu, array $retouchesChiffrageEuros): self
    {
        foreach ($retouchesChiffrageEuros as $poste => $euros) {
            if ($euros < 0) {
                throw new \InvalidArgumentException(\sprintf('Le montant retouché du poste « %s » ne peut pas être négatif.', $poste));
            }
        }

        return new self($categorieRevenu, $retouchesChiffrageEuros);
    }

    /**
     * @param array<string, mixed> $donnees
     */
    public static function fromArray(array $donnees): self
    {
        \assert(\is_string($donnees['categorie_revenu']));
        \assert(\is_array($donnees['chiffrage']));

        /** @var array<string, int> $chiffrage */
        $chiffrage = $donnees['chiffrage'];

        return self::depuis(CategorieRevenu::parNom($donnees['categorie_revenu']), $chiffrage);
    }

    public function categorieRevenu(): CategorieRevenu
    {
        return $this->categorieRevenu;
    }

    public function aDesRetouches(): bool
    {
        return [] !== $this->retouchesChiffrageEuros;
    }

    /**
     * Pose les retouches sur le chiffrage dérivé. Le dérivé reste lisible : c'est
     * lui qui est figé dans l'instantané, la retouche n'est qu'une surcouche.
     */
    public function appliquerA(Chiffrage $chiffrage): Chiffrage
    {
        $retouches = [];
        foreach ($this->retouchesChiffrageEuros as $poste => $euros) {
            $retouches[$poste] = Montant::fromEuros($euros);
        }

        return $chiffrage->avecRetouches($retouches);
    }

    /**
     * @return array{categorie_revenu: string, chiffrage: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'categorie_revenu' => $this->categorieRevenu->name,
            'chiffrage' => $this->retouchesChiffrageEuros,
        ];
    }
}
