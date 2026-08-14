<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\ORM\Mapping as ORM;

/**
 * Délai de relance d'essai (#108) : nombre de jours avant la fin de l'essai à
 * partir duquel l'email de rappel est envoyé. Réglage éditable, persisté en une
 * ligne unique (comme le seuil) ; par défaut 7 jours.
 */
#[ORM\Entity(repositoryClass: DelaiRelanceEssaiRepository::class)]
#[ORM\Table(name: 'delai_relance_essai')]
class DelaiRelanceEssai
{
    private const DEFAUT = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private readonly int $jours;

    private function __construct(int $jours)
    {
        if ($jours <= 0) {
            throw new \InvalidArgumentException('Le délai de relance doit être strictement positif.');
        }

        $this->jours = $jours;
    }

    public static function parDefaut(): self
    {
        return new self(self::DEFAUT);
    }

    public static function de(int $jours): self
    {
        return new self($jours);
    }

    public function jours(): int
    {
        return $this->jours;
    }
}
