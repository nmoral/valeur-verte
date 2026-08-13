<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

/**
 * Restitution d'une estimation (§5.3) : le chiffre n'est jamais présenté seul.
 * Il vient toujours avec son contexte — effectif, intervalle de confiance,
 * rayon retenu, millésime — et un indicateur de fiabilité dérivé (§9.7). La
 * cascade de couverture rurale (#169) l'enrichit : la portée situe le cran
 * (local / intercommunal / départemental), le palier signale le tier dégradé,
 * et les bandes appliquées disent quelles classes DPE ont été servies par leur
 * représentant de bande.
 */
final readonly class Restitution
{
    /**
     * @param array<string, string> $bandesAppliquees classe DPE saisie → représentant de sa bande
     */
    private function __construct(
        private int $montantEuros,
        private int $borneBasseEuros,
        private int $borneHauteEuros,
        private int $n,
        private ?int $rayonKm,
        private int $millesime,
        private int $prixBaseEuros,
        private bool $prixSaisiParLePro,
        private PorteeEstimation $portee,
        private PalierFiabilite $palier,
        private array $bandesAppliquees,
    ) {
    }

    /**
     * @param array<string, string> $bandesAppliquees
     */
    public static function depuis(
        int $montantEuros,
        int $borneBasseEuros,
        int $borneHauteEuros,
        int $n,
        ?int $rayonKm,
        int $millesime,
        int $prixBaseEuros,
        bool $prixSaisiParLePro,
        PorteeEstimation $portee = PorteeEstimation::Local,
        PalierFiabilite $palier = PalierFiabilite::Standard,
        array $bandesAppliquees = [],
    ): self {
        return new self($montantEuros, $borneBasseEuros, $borneHauteEuros, $n, $rayonKm, $millesime, $prixBaseEuros, $prixSaisiParLePro, $portee, $palier, $bandesAppliquees);
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

    public function portee(): PorteeEstimation
    {
        return $this->portee;
    }

    /**
     * @return array<string, string>
     */
    public function bandesAppliquees(): array
    {
        return $this->bandesAppliquees;
    }

    /**
     * Libellé court du cran retenu, pour la cellule « Rayon retenu ».
     */
    public function intitulePortee(): string
    {
        return $this->portee->intitule($this->rayonKm);
    }

    public function estDegradee(): bool
    {
        return $this->palier->estDegrade();
    }

    public function fiabilite(): IndicateurFiabilite
    {
        return IndicateurFiabilite::depuis($this->n, $this->rayonKm);
    }

    public function phrase(): string
    {
        return \sprintf(
            '+%s € (fourchette %s – %s €, sur %d transactions %s)',
            $this->euros($this->montantEuros),
            $this->euros($this->borneBasseEuros),
            $this->euros($this->borneHauteEuros),
            $this->n,
            $this->portee->formulation($this->rayonKm),
        );
    }

    private function euros(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }
}
