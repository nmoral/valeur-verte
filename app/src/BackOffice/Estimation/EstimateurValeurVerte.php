<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use App\BackOffice\Audit\SaisieAudit;

/**
 * Chaîne de calcul économique (§6, #25) : à partir d'une saisie audit et d'une
 * commune, produit la restitution chiffrée (#31) et le contexte de calcul employé
 * (#33). Le prix de base vaut par défaut la médiane du prix au m² de la maille
 * multipliée par la surface bâtie, que le pro peut remplacer par sa propre estimation.
 */
final readonly class EstimateurValeurVerte
{
    public function __construct(
        private FournisseurCoefficientMaille $coefficients,
        private FournisseurPrixMaille $prix,
    ) {
    }

    public function estimer(string $codeInsee, SaisieAudit $saisie, ?int $prixBaseOverride = null): ResultatEstimation
    {
        $maille = $this->coefficients->pour($codeInsee);
        if (null === $maille) {
            throw EstimationIndisponible::communeHorsCouverture($codeInsee);
        }

        $prixBase = $prixBaseOverride ?? $this->prixBaseParDefaut($codeInsee, $saisie);
        $restitution = $maille->estimer($saisie->dpeAvant(), $saisie->dpeApres(), $prixBase, null !== $prixBaseOverride);

        return ResultatEstimation::depuis(
            $restitution,
            $maille->contexte($saisie->dpeAvant(), $saisie->dpeApres()),
            $maille->effetDeSeuil($saisie->dpeAvant(), $saisie->dpeApres(), $prixBase),
        );
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
