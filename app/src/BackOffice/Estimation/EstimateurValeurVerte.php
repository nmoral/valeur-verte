<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Restitution\Restitution;

/**
 * Chaîne de calcul économique (§6, #25) : à partir d'une saisie audit et d'une
 * commune, produit la restitution chiffrée (#31). Le prix de base vaut par défaut
 * la médiane du prix au m² de la maille multipliée par la surface bâtie, que le
 * pro peut remplacer par sa propre estimation.
 */
final readonly class EstimateurValeurVerte
{
    public function __construct(
        private FournisseurCoefficientMaille $coefficients,
        private FournisseurPrixMaille $prix,
    ) {
    }

    public function estimer(string $codeInsee, SaisieAudit $saisie, ?int $prixBaseOverride = null): Restitution
    {
        $maille = $this->coefficients->pour($codeInsee);
        if (null === $maille) {
            throw EstimationIndisponible::communeHorsCouverture($codeInsee);
        }

        $prixBase = $prixBaseOverride ?? $this->prixBaseParDefaut($codeInsee, $saisie);

        return $maille->estimer($saisie->dpeAvant(), $saisie->dpeApres(), $prixBase);
    }

    private function prixBaseParDefaut(string $codeInsee, SaisieAudit $saisie): int
    {
        $prixM2Median = $this->prix->prixM2Median($codeInsee);
        if (null === $prixM2Median) {
            throw EstimationIndisponible::prixDeBaseInconnu($codeInsee);
        }

        return (int) round($prixM2Median * $saisie->surfaceBatie());
    }
}
