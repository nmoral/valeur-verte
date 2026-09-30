<?php

declare(strict_types=1);

namespace App\Simulateur;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une utilisation du simulateur public (#385), **strictement anonyme** : une ligne
 * par estimation demandée, sans aucune donnée personnelle. Sert de compteur
 * d'usage (volume, taux de couverture) — jamais rattachée à un email, même quand
 * le visiteur a par ailleurs consenti à la conservation de son adresse.
 *
 * Volontairement **pas** {@see \App\Tenant\TenantAwareInterface} : le simulateur
 * public n'appartient à aucun pro.
 */
#[ORM\Entity(repositoryClass: UtilisationRepository::class)]
#[ORM\Table(name: 'simulateur_utilisation')]
class Utilisation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'cree_le', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private bool $disponible;

    #[ORM\Column(name: 'code_departement', length: 3, nullable: true)]
    private ?string $codeDepartement;

    private function __construct(bool $disponible, ?string $codeDepartement, \DateTimeImmutable $creeLe)
    {
        $this->disponible = $disponible;
        $this->codeDepartement = $codeDepartement;
        $this->creeLe = $creeLe;
    }

    public static function estimation(bool $disponible, ?string $codeDepartement, ?\DateTimeImmutable $creeLe = null): self
    {
        return new self($disponible, $codeDepartement, $creeLe ?? new \DateTimeImmutable());
    }

    public function estDisponible(): bool
    {
        return $this->disponible;
    }

    public function codeDepartement(): ?string
    {
        return $this->codeDepartement;
    }

    public function creeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
