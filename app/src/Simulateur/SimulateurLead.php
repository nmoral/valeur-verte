<?php

declare(strict_types=1);

namespace App\Simulateur;

use App\BackOffice\Rapport\Dpe;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lead du simulateur public (#385) : un email **consenti**, conservé pour
 * recontact ultérieur. On n'écrit une ligne ici que si le visiteur a coché
 * explicitement la conservation (décochée par défaut) ; sinon l'email n'a servi
 * qu'à l'envoi du rapport et n'est pas persisté.
 *
 * Volontairement **pas** {@see \App\Tenant\TenantAwareInterface} : le simulateur
 * public n'appartient à aucun pro.
 */
#[ORM\Entity(repositoryClass: SimulateurLeadRepository::class)]
#[ORM\Table(name: 'simulateur_lead')]
class SimulateurLead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(name: 'code_insee', length: 5)]
    private string $codeInsee;

    #[ORM\Column(name: 'commune_nom', type: Types::TEXT, nullable: true)]
    private ?string $communeNom;

    #[ORM\Column(name: 'surface_batie')]
    private int $surfaceBatie;

    #[ORM\Column(name: 'surface_terrain')]
    private int $surfaceTerrain;

    #[ORM\Column(name: 'dpe_avant', length: 1)]
    private string $dpeAvant;

    #[ORM\Column(name: 'dpe_apres', length: 1)]
    private string $dpeApres;

    #[ORM\Column(name: 'montant_estime', nullable: true)]
    private ?int $montantEstime;

    #[ORM\Column(name: 'cree_le', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    private function __construct(
        string $email,
        string $codeInsee,
        ?string $communeNom,
        int $surfaceBatie,
        int $surfaceTerrain,
        Dpe $dpeAvant,
        Dpe $dpeApres,
        ?int $montantEstime,
        \DateTimeImmutable $creeLe,
    ) {
        $this->email = $email;
        $this->codeInsee = $codeInsee;
        $this->communeNom = $communeNom;
        $this->surfaceBatie = $surfaceBatie;
        $this->surfaceTerrain = $surfaceTerrain;
        $this->dpeAvant = $dpeAvant->value;
        $this->dpeApres = $dpeApres->value;
        $this->montantEstime = $montantEstime;
        $this->creeLe = $creeLe;
    }

    public static function creer(
        string $email,
        string $codeInsee,
        ?string $communeNom,
        int $surfaceBatie,
        int $surfaceTerrain,
        Dpe $dpeAvant,
        Dpe $dpeApres,
        ?int $montantEstime,
        ?\DateTimeImmutable $creeLe = null,
    ): self {
        return new self(
            $email,
            $codeInsee,
            $communeNom,
            $surfaceBatie,
            $surfaceTerrain,
            $dpeAvant,
            $dpeApres,
            $montantEstime,
            $creeLe ?? new \DateTimeImmutable(),
        );
    }

    public function email(): string
    {
        return $this->email;
    }

    public function codeInsee(): string
    {
        return $this->codeInsee;
    }

    public function creeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
