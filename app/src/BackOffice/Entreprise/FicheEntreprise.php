<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Fiche entreprise pré-remplie à partir du SIRET : identité (Recherche
 * d'Entreprises) enrichie des qualifications RGE (ADEME). Value object immuable,
 * exploitable même sans qualification RGE.
 */
final readonly class FicheEntreprise
{
    /**
     * @param list<string> $domainesRge
     */
    private function __construct(
        private Siret $siret,
        private string $raisonSociale,
        private string $adresse,
        private string $codeNaf,
        private ?string $effectif,
        private StatutRge $statutRge,
        private array $domainesRge,
    ) {
    }

    /**
     * @param list<string> $domainesRge
     */
    public static function creer(
        Siret $siret,
        string $raisonSociale,
        string $adresse,
        string $codeNaf,
        ?string $effectif,
        StatutRge $statutRge,
        array $domainesRge,
    ): self {
        return new self(
            $siret,
            trim($raisonSociale),
            trim($adresse),
            trim($codeNaf),
            $effectif,
            $statutRge,
            $domainesRge,
        );
    }

    public function siret(): Siret
    {
        return $this->siret;
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

    public function statutRge(): StatutRge
    {
        return $this->statutRge;
    }

    /**
     * @return list<string>
     */
    public function domainesRge(): array
    {
        return $this->domainesRge;
    }
}
