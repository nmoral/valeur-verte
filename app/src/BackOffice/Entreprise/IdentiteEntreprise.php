<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Identité administrative d'un établissement, issue de l'API Recherche
 * d'Entreprises : raison sociale, adresse, code NAF, effectif. Les
 * qualifications RGE sont ajoutées séparément par l'agrégation.
 */
final readonly class IdentiteEntreprise
{
    private function __construct(
        private string $raisonSociale,
        private string $adresse,
        private string $codeNaf,
        private ?string $effectif,
    ) {
    }

    public static function creer(string $raisonSociale, string $adresse, string $codeNaf, ?string $effectif): self
    {
        return new self(trim($raisonSociale), trim($adresse), trim($codeNaf), $effectif);
    }

    public function raisonSociale(): string
    {
        return $this->raisonSociale;
    }

    public function adresse(): string
    {
        return $this->adresse;
    }

    public function codeNaf(): string
    {
        return $this->codeNaf;
    }

    public function effectif(): ?string
    {
        return $this->effectif;
    }
}
