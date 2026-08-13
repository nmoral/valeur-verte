<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

/**
 * Restitution d'une estimation (§5.3) : le chiffre n'est jamais présenté seul.
 * Il vient toujours avec son contexte — effectif, intervalle de confiance,
 * rayon retenu, millésime — et un indicateur de fiabilité dérivé (§9.7). Un
 * rayon absent traduit le repli départemental.
 */
final readonly class Restitution
{
    private function __construct(
        private int $montantEuros,
        private int $borneBasseEuros,
        private int $borneHauteEuros,
        private int $n,
        private ?int $rayonKm,
        private int $millesime,
        private int $prixBaseEuros,
        private bool $prixSaisiParLePro,
    ) {
    }

    public static function depuis(
        int $montantEuros,
        int $borneBasseEuros,
        int $borneHauteEuros,
        int $n,
        ?int $rayonKm,
        int $millesime,
        int $prixBaseEuros,
        bool $prixSaisiParLePro,
    ): self {
        return new self($montantEuros, $borneBasseEuros, $borneHauteEuros, $n, $rayonKm, $millesime, $prixBaseEuros, $prixSaisiParLePro);
    }

    public function montantEuros(): int
    {
        return $this->montantEuros;
    }

    public function borneBasseEuros(): int
    {
        return $this->borneBasseEuros;
    }

    public function borneHauteEuros(): int
    {
        return $this->borneHauteEuros;
    }

    public function n(): int
    {
        return $this->n;
    }

    public function rayonKm(): ?int
    {
        return $this->rayonKm;
    }

    public function millesime(): int
    {
        return $this->millesime;
    }

    public function prixBaseEuros(): int
    {
        return $this->prixBaseEuros;
    }

    public function prixSaisiParLePro(): bool
    {
        return $this->prixSaisiParLePro;
    }

    public function fiabilite(): IndicateurFiabilite
    {
        return IndicateurFiabilite::depuis($this->n, $this->rayonKm);
    }

    public function phrase(): string
    {
        $portee = null === $this->rayonKm
            ? 'au niveau départemental'
            : \sprintf('dans un rayon de %d km', $this->rayonKm);

        return \sprintf(
            '+%s € (fourchette %s – %s €, sur %d transactions %s)',
            $this->euros($this->montantEuros),
            $this->euros($this->borneBasseEuros),
            $this->euros($this->borneHauteEuros),
            $this->n,
            $portee,
        );
    }

    private function euros(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }
}
