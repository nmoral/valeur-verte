<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

/**
 * Rapport de valeur verte. Un rapport porteur d'un contact est un lead : les
 * deux vivent dans une seule liste (§9.2). La persistance Doctrine est ajoutée
 * dans une tranche ultérieure (mapping sur le schéma `app`).
 */
class Rapport
{
    private function __construct(
        private string $reference,
        private \DateTimeImmutable $creeLe,
        private Source $source,
        private string $adresse,
        private Dpe $dpeAvant,
        private Dpe $dpeApres,
        private int $valeurVerteEstimee,
        private string $pdfUrl,
        private ?Contact $contact,
    ) {
    }

    public static function creer(
        string $reference,
        \DateTimeImmutable $creeLe,
        Source $source,
        string $adresse,
        Dpe $dpeAvant,
        Dpe $dpeApres,
        int $valeurVerteEstimee,
        string $pdfUrl,
        ?Contact $contact = null,
    ): self {
        $reference = trim($reference);
        if ('' === $reference) {
            throw new \InvalidArgumentException('La référence du rapport ne peut pas être vide.');
        }

        return new self(
            $reference,
            $creeLe,
            $source,
            $adresse,
            $dpeAvant,
            $dpeApres,
            $valeurVerteEstimee,
            $pdfUrl,
            $contact,
        );
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function creeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function adresse(): string
    {
        return $this->adresse;
    }

    public function dpeAvant(): Dpe
    {
        return $this->dpeAvant;
    }

    public function dpeApres(): Dpe
    {
        return $this->dpeApres;
    }

    public function valeurVerteEstimee(): int
    {
        return $this->valeurVerteEstimee;
    }

    public function pdfUrl(): string
    {
        return $this->pdfUrl;
    }

    public function contact(): ?Contact
    {
        return $this->contact;
    }

    public function estUnLead(): bool
    {
        return null !== $this->contact;
    }
}
