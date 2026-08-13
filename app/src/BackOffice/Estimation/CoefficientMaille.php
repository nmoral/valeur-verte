<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use App\BackOffice\Rapport\Dpe;
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
     * @param array<string, float> $coefDpe effets log par étiquette DPE publiée
     */
    private function __construct(
        private int $millesime,
        private ?int $rayonKm,
        private int $n,
        private float $erreurMediane,
        private array $coefDpe,
    ) {
    }

    /**
     * @param array<string, float> $coefDpe
     */
    public static function depuis(
        int $millesime,
        ?int $rayonKm,
        int $n,
        float $erreurMediane,
        array $coefDpe,
    ): self {
        return new self($millesime, $rayonKm, $n, $erreurMediane, $coefDpe);
    }

    /**
     * Estime la valeur verte créée en passant de ``$avant`` à ``$apres``, à
     * partir d'un prix de base (§6) : valeur = P × (exp(Δ) − 1), Δ étant l'écart
     * des coefficients log. La fourchette applique l'erreur médiane de la maille.
     */
    public function estimer(Dpe $avant, Dpe $apres, int $prixBaseEuros, bool $prixSaisiParLePro): Restitution
    {
        $ecartLog = $this->coefficient($apres) - $this->coefficient($avant);
        $valeur = $prixBaseEuros * (exp($ecartLog) - 1.0);
        $fraction = $this->erreurMediane / 100.0;

        return Restitution::depuis(
            montantEuros: (int) round($valeur),
            borneBasseEuros: (int) round($valeur * (1.0 - $fraction)),
            borneHauteEuros: (int) round($valeur * (1.0 + $fraction)),
            n: $this->n,
            rayonKm: $this->rayonKm,
            millesime: $this->millesime,
            prixBaseEuros: $prixBaseEuros,
            prixSaisiParLePro: $prixSaisiParLePro,
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

    private function coefficient(Dpe $classe): float
    {
        if (!\array_key_exists($classe->value, $this->coefDpe)) {
            throw new ClasseNonPubliee($classe);
        }

        return $this->coefDpe[$classe->value];
    }
}
