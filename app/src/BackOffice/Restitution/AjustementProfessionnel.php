<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

/**
 * Ligne d'ajustement du professionnel (#30, §4) : une latitude laissée au pro,
 * jamais une réécriture du coefficient statistique — « un chiffre gonflé par
 * celui qui a intérêt à le gonfler perd sa valeur probante ».
 *
 * C'est une **ligne séparée** : un montant signé (en moins ou en plus) et un
 * motif obligatoire attribué au pro. L'estimation retenue s'en déduit sans
 * jamais toucher la mesure.
 */
final readonly class AjustementProfessionnel
{
    private function __construct(
        private int $montantEuros,
        private string $motif,
    ) {
    }

    public static function depuis(int $montantEuros, string $motif): self
    {
        if (0 === $montantEuros) {
            throw new AjustementInvalide('Saisissez un montant différent de zéro : positif pour majorer l\'estimation, négatif pour la minorer.');
        }

        $motif = trim($motif);
        if ('' === $motif) {
            throw new AjustementInvalide('Le motif de l\'ajustement est obligatoire.');
        }

        return new self($montantEuros, $motif);
    }

    /**
     * @param array<string, mixed> $donnees
     */
    public static function fromArray(array $donnees): self
    {
        \assert(\is_int($donnees['montant_euros']));
        \assert(\is_string($donnees['motif']));

        return self::depuis($donnees['montant_euros'], $donnees['motif']);
    }

    public function montantEuros(): int
    {
        return $this->montantEuros;
    }

    public function motif(): string
    {
        return $this->motif;
    }

    /**
     * Estimation retenue = mesure statistique + ajustement du pro. La mesure
     * n'est jamais altérée ; l'ajustement s'y ajoute (§4).
     */
    public function estimationRetenue(int $mesureEuros): int
    {
        return $mesureEuros + $this->montantEuros;
    }

    /**
     * @return array{montant_euros: int, motif: string}
     */
    public function toArray(): array
    {
        return [
            'montant_euros' => $this->montantEuros,
            'motif' => $this->motif,
        ];
    }
}
