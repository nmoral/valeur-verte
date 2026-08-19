<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\EffetDeSeuil;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use App\BackOffice\Restitution\Restitution;

/**
 * Coefficients pré-calculés d'une commune (§10.6), lus depuis
 * ``data.coefficient_maille``. Le modèle est log-prix : ``coef_dpe`` porte des
 * effets en log par étiquette. La chaîne de calcul économique (§6, #25) convertit
 * l'écart de coefficients en euros à partir d'un prix de base, et produit la
 * restitution (§5.3, #31).
 */
final readonly class CoefficientMaille
{
    /**
     * @param array<string, float>               $coefDpe effets log par étiquette DPE publiée
     * @param array<string, string>              $bandes  classe DPE → représentant de sa bande (vide au cran local, #169)
     * @param array<string, array{float, float}> $icDpe   intervalle [bas, haut] de l'effet log par classe (#260)
     */
    private function __construct(
        private int $millesime,
        private ?int $rayonKm,
        private int $n,
        private float $erreurMediane,
        private array $coefDpe,
        private PorteeEstimation $portee,
        private PalierFiabilite $palier,
        private array $bandes,
        private array $icDpe,
    ) {
    }

    /**
     * @param array<string, float>               $coefDpe
     * @param array<string, string>              $bandes
     * @param array<string, array{float, float}> $icDpe
     */
    public static function depuis(
        int $millesime,
        ?int $rayonKm,
        int $n,
        float $erreurMediane,
        array $coefDpe,
        PorteeEstimation $portee = PorteeEstimation::Local,
        PalierFiabilite $palier = PalierFiabilite::Standard,
        array $bandes = [],
        array $icDpe = [],
    ): self {
        return new self($millesime, $rayonKm, $n, $erreurMediane, $coefDpe, $portee, $palier, $bandes, $icDpe);
    }

    /**
     * Estime la valeur verte créée en passant de ``$avant`` à ``$apres``, à
     * partir d'un prix de base (§6) : valeur = P × (exp(Δ) − 1), Δ étant l'écart
     * des coefficients log. La fourchette découle de l'**incertitude des
     * coefficients** (`ic_dpe`, #260) : demi-largeur de l'écart = √(demi_avant² +
     * demi_apres²) (indépendance ; nulle si même classe, écart exactement nul),
     * convertie en € par exp. Repli sur l'erreur médiane quand `ic_dpe` manque.
     */
    public function estimer(Dpe $avant, Dpe $apres, int $prixBaseEuros, bool $prixSaisiParLePro): Restitution
    {
        $ecartLog = $this->coefficient($apres) - $this->coefficient($avant);
        $valeur = $prixBaseEuros * (exp($ecartLog) - 1.0);
        [$borneBasse, $borneHaute] = $this->fourchette($avant, $apres, $ecartLog, $prixBaseEuros, $valeur);

        return Restitution::depuis(
            montantEuros: (int) round($valeur),
            borneBasseEuros: (int) round($borneBasse),
            borneHauteEuros: (int) round($borneHaute),
            n: $this->n,
            rayonKm: $this->rayonKm,
            millesime: $this->millesime,
            prixBaseEuros: $prixBaseEuros,
            prixSaisiParLePro: $prixSaisiParLePro,
            portee: $this->portee,
            palier: $this->palier,
            bandesAppliquees: $this->bandesAppliquees($avant, $apres),
        );
    }

    /**
     * Contexte de calcul employé pour figer l'instantané (#33) : les coefficients
     * log des deux étiquettes et l'erreur médiane de la maille.
     */
    public function contexte(Dpe $avant, Dpe $apres): ContexteCalcul
    {
        return ContexteCalcul::depuis($this->coefficient($avant), $this->coefficient($apres), $this->erreurMediane);
    }

    /**
     * Effet de seuil C (#27) : la table des gains depuis la classe actuelle vers
     * chaque classe publiée meilleure. Null quand le contraste manque (§6).
     */
    public function effetDeSeuil(Dpe $avant, Dpe $apres, int $prixBaseEuros): ?EffetDeSeuil
    {
        return EffetDeSeuil::calculer($avant, $apres, $this->coefDpe, $prixBaseEuros);
    }

    private function coefficient(Dpe $classe): float
    {
        $representant = $this->representant($classe);
        if (!\array_key_exists($representant, $this->coefDpe)) {
            throw new ClasseNonPubliee($classe);
        }

        return $this->coefDpe[$representant];
    }

    private function representant(Dpe $classe): string
    {
        return $this->bandes[$classe->value] ?? $classe->value;
    }

    /**
     * Fourchette [basse, haute] en euros. Depuis `ic_dpe` (#260) : demi-largeur de
     * l'écart des coefficients (√ somme des carrés des demi-IC, nulle si même
     * classe → écart exactement nul), convertie en € par exp. Repli sur l'erreur
     * médiane si l'un des deux effets n'a pas d'intervalle publié.
     *
     * @return array{float, float}
     */
    private function fourchette(Dpe $avant, Dpe $apres, float $ecartLog, int $prixBaseEuros, float $valeur): array
    {
        $demiAvant = $this->demiIc($avant);
        $demiApres = $this->demiIc($apres);
        if (null === $demiAvant || null === $demiApres) {
            $fraction = $this->erreurMediane / 100.0;

            return [$valeur * (1.0 - $fraction), $valeur * (1.0 + $fraction)];
        }

        $demiEcart = $this->representant($avant) === $this->representant($apres)
            ? 0.0
            : sqrt($demiAvant ** 2 + $demiApres ** 2);

        return [
            $prixBaseEuros * (exp($ecartLog - $demiEcart) - 1.0),
            $prixBaseEuros * (exp($ecartLog + $demiEcart) - 1.0),
        ];
    }

    /**
     * Demi-largeur de l'intervalle de l'effet DPE (via représentant de bande), ou
     * ``null`` si la classe servie n'a pas d'intervalle publié (repli erreur médiane).
     */
    private function demiIc(Dpe $classe): ?float
    {
        $intervalle = $this->icDpe[$this->representant($classe)] ?? null;
        if (null === $intervalle) {
            return null;
        }

        return ($intervalle[1] - $intervalle[0]) / 2.0;
    }

    /**
     * Substitutions de bande réellement employées par l'estimation : seules les
     * classes avant/après dont le représentant diffère (classe fusionnée, ex.
     * G → F) sont signalées ; une bande identité ou l'absence de bande ne l'est pas.
     *
     * @return array<string, string>
     */
    private function bandesAppliquees(Dpe $avant, Dpe $apres): array
    {
        $appliquees = [];
        foreach ([$avant, $apres] as $classe) {
            $representant = $this->representant($classe);
            if ($representant !== $classe->value) {
                $appliquees[$classe->value] = $representant;
            }
        }

        return $appliquees;
    }
}
