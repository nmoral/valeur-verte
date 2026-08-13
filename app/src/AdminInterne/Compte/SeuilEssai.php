<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use Doctrine\ORM\Mapping as ORM;

/**
 * Seuil d'essai (#47/#65) : nombre de rapports offerts avant bascule payant.
 * Réglage éditable dans l'admin, persisté en une ligne unique (comme la marque) ;
 * par défaut 10 (à calibrer, #65).
 */
#[ORM\Entity(repositoryClass: SeuilEssaiRepository::class)]
#[ORM\Table(name: 'seuil_essai')]
class SeuilEssai
{
    private const DEFAUT = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private readonly int $valeur;

    private function __construct(int $valeur)
    {
        if ($valeur <= 0) {
            throw new \InvalidArgumentException("Le seuil d'essai doit être strictement positif.");
        }

        $this->valeur = $valeur;
    }

    public static function parDefaut(): self
    {
        return new self(self::DEFAUT);
    }

    public static function de(int $valeur): self
    {
        return new self($valeur);
    }

    public function valeur(): int
    {
        return $this->valeur;
    }
}
