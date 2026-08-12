<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rapport de valeur verte. Un rapport porteur d'un contact est un lead : les
 * deux vivent dans une seule liste (§9.2).
 *
 * Le contact (value object optionnel) est mappé en trois colonnes nullables et
 * reconstruit à la lecture : Doctrine ne sait pas rendre un embeddable nullable.
 */
#[ORM\Entity(repositoryClass: RapportRepository::class)]
#[ORM\Table(name: 'rapport')]
class Rapport implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $reference;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(enumType: Source::class)]
    private Source $source;

    #[ORM\Column(length: 255)]
    private string $adresse;

    #[ORM\Column(enumType: Dpe::class, length: 1)]
    private Dpe $dpeAvant;

    #[ORM\Column(enumType: Dpe::class, length: 1)]
    private Dpe $dpeApres;

    #[ORM\Column]
    private int $valeurVerteEstimee;

    #[ORM\Column(length: 255)]
    private string $pdfUrl;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactNom;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $contactTelephone;

    private function __construct(
        string $reference,
        \DateTimeImmutable $creeLe,
        Source $source,
        string $adresse,
        Dpe $dpeAvant,
        Dpe $dpeApres,
        int $valeurVerteEstimee,
        string $pdfUrl,
        ?Contact $contact,
    ) {
        $this->reference = $reference;
        $this->creeLe = $creeLe;
        $this->source = $source;
        $this->adresse = $adresse;
        $this->dpeAvant = $dpeAvant;
        $this->dpeApres = $dpeApres;
        $this->valeurVerteEstimee = $valeurVerteEstimee;
        $this->pdfUrl = $pdfUrl;
        $this->contactNom = $contact?->nom();
        $this->contactEmail = $contact?->email();
        $this->contactTelephone = $contact?->telephone();
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

    public function id(): ?int
    {
        return $this->id;
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
        if (null === $this->contactNom) {
            return null;
        }

        return Contact::creer($this->contactNom, $this->contactEmail, $this->contactTelephone);
    }

    public function estUnLead(): bool
    {
        return null !== $this->contactNom;
    }
}
