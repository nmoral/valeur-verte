<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage persisté des paramètres d'une formule (#61/#65) : une ligne unique par
 * formule, éditable dans l'admin. Adaptateur entre le value object
 * {@see ParametresFormule} et la base — le domaine ne dépend pas de Doctrine.
 */
#[ORM\Entity(repositoryClass: ReglagesFormuleRepository::class)]
#[ORM\Table(name: 'reglage_formule')]
class ReglageFormule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: Formule::class, unique: true)]
    private Formule $formule;

    #[ORM\Column]
    private int $dureeEssaiJours;

    #[ORM\Column]
    private int $quotaRapports;

    #[ORM\Column(nullable: true)]
    private ?int $plafondComptes;

    private function __construct(Formule $formule, ParametresFormule $parametres)
    {
        $this->formule = $formule;
        $this->appliquer($parametres);
    }

    public static function depuis(Formule $formule, ParametresFormule $parametres): self
    {
        return new self($formule, $parametres);
    }

    public function formule(): Formule
    {
        return $this->formule;
    }

    public function parametres(): ParametresFormule
    {
        return new ParametresFormule($this->dureeEssaiJours, $this->quotaRapports, $this->plafondComptes);
    }

    public function mettreAJour(ParametresFormule $parametres): void
    {
        $this->appliquer($parametres);
    }

    private function appliquer(ParametresFormule $parametres): void
    {
        $this->dureeEssaiJours = $parametres->dureeEssaiJours();
        $this->quotaRapports = $parametres->quotaRapports();
        $this->plafondComptes = $parametres->plafondComptes();
    }
}
