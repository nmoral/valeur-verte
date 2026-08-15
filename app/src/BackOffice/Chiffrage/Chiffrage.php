<?php

declare(strict_types=1);

namespace App\BackOffice\Chiffrage;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\Poste;

/**
 * Chiffrage des travaux retenus (#26) : bouquet (#29) × grille du pro (#34) × quantités.
 *
 * Les montants sont **hors taxes** : c'est ainsi qu'un pro chiffre, et c'est l'assiette
 * qu'attend le plafond de dépenses de la rénovation d'ampleur (cf. {@see \App\BackOffice\Aide\BaremeAides}).
 *
 * L'objet porte deux états. Le **dérivé** est ce que la grille disait au moment de
 * l'audit : il part figé dans l'instantané du rapport (§7 — rapport reproductible).
 * Les **retouches** du pro se posent par-dessus sans l'effacer, comme l'ajustement (#30)
 * et le prix saisi par le pro. On doit toujours pouvoir répondre « voici ce que votre
 * grille disait, voici ce que vous avez saisi » — et revenir en arrière.
 */
final readonly class Chiffrage
{
    /**
     * @param array<string, Montant> $derives   indexé par Poste->name, ordre du bouquet
     * @param array<string, Montant> $retouches indexé par Poste->name, sous-ensemble des dérivés
     */
    private function __construct(
        private array $derives,
        private array $retouches,
    ) {
    }

    public static function derive(Bouquet $bouquet, GrilleCout $grille, QuantitesTravaux $quantites): self
    {
        $derives = [];
        foreach ($bouquet->postes() as $poste) {
            $derives[$poste->name] = Montant::fromEuros(
                $grille->montant($poste)->euros() * $quantites->pour($poste),
            );
        }

        return new self($derives, []);
    }

    /**
     * Recompose un chiffrage figé (relecture de l'instantané, §9.1) : les montants
     * sont pris tels quels, sans repasser par la grille — qui a pu changer depuis.
     *
     * @param array<string, Montant> $derives indexé par Poste->name
     */
    public static function figee(array $derives): self
    {
        return new self($derives, []);
    }

    /**
     * Montants issus de la grille, en euros, pour le figeage dans l'instantané.
     *
     * @return array<string, int>
     */
    public function montantsDerives(): array
    {
        $montants = [];
        foreach ($this->derives as $nom => $montant) {
            $montants[$nom] = $montant->euros();
        }

        return $montants;
    }

    /**
     * @param array<string, Montant> $retouches indexé par Poste->name
     */
    public function avecRetouches(array $retouches): self
    {
        foreach ($retouches as $nom => $montant) {
            if (!isset($this->derives[$nom])) {
                throw new \InvalidArgumentException(\sprintf('Le poste « %s » ne fait pas partie du bouquet chiffré.', $nom));
            }

            if ($montant->euros() < 0) {
                throw new \InvalidArgumentException(\sprintf('Le montant retouché du poste « %s » ne peut pas être négatif.', $nom));
            }
        }

        return new self($this->derives, $retouches);
    }

    public function montant(Poste $poste): Montant
    {
        return $this->retouches[$poste->name] ?? $this->montantDerive($poste);
    }

    /**
     * Montant issu de la grille, insensible aux retouches : c'est la trace figée.
     */
    public function montantDerive(Poste $poste): Montant
    {
        return $this->derives[$poste->name]
            ?? throw new \OutOfBoundsException(\sprintf('Le poste « %s » ne fait pas partie du bouquet chiffré.', $poste->name));
    }

    public function estRetouche(Poste $poste): bool
    {
        return isset($this->retouches[$poste->name]);
    }

    /**
     * @return list<Poste>
     */
    public function postes(): array
    {
        return array_map(
            static fn (string $nom): Poste => Poste::parNom($nom),
            array_keys($this->derives),
        );
    }

    public function total(): Montant
    {
        $total = 0;
        foreach ($this->postes() as $poste) {
            $total += $this->montant($poste)->euros();
        }

        return Montant::fromEuros($total);
    }
}
