<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

/**
 * Contexte de calcul réellement employé pour une estimation (#33, §9.1) : les
 * coefficients log des deux étiquettes DPE et l'erreur médiane de la maille.
 * Figé dans l'instantané JSONB pour la traçabilité (D5) : « coefficients utilisés ».
 */
final readonly class ContexteCalcul
{
    private function __construct(
        private float $coefDpeAvant,
        private float $coefDpeApres,
        private float $erreurMediane,
    ) {
    }

    public static function depuis(float $coefDpeAvant, float $coefDpeApres, float $erreurMediane): self
    {
        return new self($coefDpeAvant, $coefDpeApres, $erreurMediane);
    }

    /**
     * @param array{coef_dpe_avant: float|int, coef_dpe_apres: float|int, erreur_mediane: float|int} $donnees
     */
    public static function fromArray(array $donnees): self
    {
        return new self(
            (float) $donnees['coef_dpe_avant'],
            (float) $donnees['coef_dpe_apres'],
            (float) $donnees['erreur_mediane'],
        );
    }

    public function coefDpeAvant(): float
    {
        return $this->coefDpeAvant;
    }

    public function coefDpeApres(): float
    {
        return $this->coefDpeApres;
    }

    public function erreurMediane(): float
    {
        return $this->erreurMediane;
    }

    /**
     * @return array{coef_dpe_avant: float, coef_dpe_apres: float, erreur_mediane: float}
     */
    public function toArray(): array
    {
        return [
            'coef_dpe_avant' => $this->coefDpeAvant,
            'coef_dpe_apres' => $this->coefDpeApres,
            'erreur_mediane' => $this->erreurMediane,
        ];
    }
}
