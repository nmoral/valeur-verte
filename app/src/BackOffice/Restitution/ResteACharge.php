<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

use App\BackOffice\Aide\AideRenovationAmpleur;
use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\GrilleCout\Montant;

/**
 * Reste à charge estimé (#26) : coût des travaux HT − aide MaPrimeRénov'.
 *
 * Quand la situation du ménage n'est pas déclarée, on ne devine pas : le reste est
 * une fourchette bornée par les taux extrêmes du barème, qui se resserre en un
 * montant unique dès que le pro précise la tranche.
 *
 * Montant **estimatif** : il ne préjuge ni de l'éligibilité du ménage ni de
 * l'instruction du dossier par l'Anah.
 */
final readonly class ResteACharge
{
    private function __construct(
        private Montant $coutHt,
        private Montant $aideMinimum,
        private Montant $aideMaximum,
        private bool $ouvreDroit,
    ) {
    }

    public static function calculer(
        Chiffrage $chiffrage,
        int $gainDeClasses,
        CategorieRevenu $categorie,
        BaremeAides $bareme,
    ): self {
        $coutHt = $chiffrage->total();

        if (CategorieRevenu::NonPrecisee === $categorie) {
            $fourchette = AideRenovationAmpleur::fourchette($coutHt, $gainDeClasses, $bareme);

            return new self($coutHt, $fourchette->minimum(), $fourchette->maximum(), $bareme->ouvreDroit($gainDeClasses));
        }

        $aide = AideRenovationAmpleur::pour($coutHt, $gainDeClasses, $categorie, $bareme);

        return new self($coutHt, $aide, $aide, $bareme->ouvreDroit($gainDeClasses));
    }

    public function coutHt(): Montant
    {
        return $this->coutHt;
    }

    public function aideMinimum(): Montant
    {
        return $this->aideMinimum;
    }

    public function aideMaximum(): Montant
    {
        return $this->aideMaximum;
    }

    /**
     * Le reste le plus favorable — donc celui adossé à l'aide **maximum**.
     */
    public function minimum(): Montant
    {
        return Montant::fromEuros($this->coutHt->euros() - $this->aideMaximum->euros());
    }

    public function maximum(): Montant
    {
        return Montant::fromEuros($this->coutHt->euros() - $this->aideMinimum->euros());
    }

    public function estUneFourchette(): bool
    {
        return $this->aideMinimum->euros() !== $this->aideMaximum->euros();
    }

    public function ouvreDroit(): bool
    {
        return $this->ouvreDroit;
    }
}
