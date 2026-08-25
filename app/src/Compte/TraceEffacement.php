<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'un effacement de compte (#332), écrite à l'effacement définitif (#308)
 * dans la même transaction que la suppression. Elle ne conserve **jamais
 * l'adresse en clair** — seulement son {@see EmpreinteEffacement} (HMAC) — et
 * porte l'état d'essai **consommé** (rapports produits, fin d'essai) au moment de
 * l'effacement. À la réinscription, une empreinte retrouvée prive le compte
 * recréé d'un nouvel essai (sans refuser l'inscription). Purge à 12 mois (#332).
 */
#[ORM\Entity(repositoryClass: TraceEffacementRepository::class)]
#[ORM\Table(name: 'trace_effacement')]
#[ORM\UniqueConstraint(name: 'uniq_trace_effacement_empreinte', columns: ['empreinte'])]
class TraceEffacement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column(name: 'efface_le', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $effaceLe;

    #[ORM\Column(name: 'rapports_consommes')]
    private int $rapportsConsommes;

    #[ORM\Column(name: 'fin_essai', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finEssai;

    private function __construct(
        string $empreinte,
        \DateTimeImmutable $effaceLe,
        int $rapportsConsommes,
        ?\DateTimeImmutable $finEssai,
    ) {
        $this->empreinte = $empreinte;
        $this->effaceLe = $effaceLe;
        $this->rapportsConsommes = $rapportsConsommes;
        $this->finEssai = $finEssai;
    }

    public static function creer(
        string $empreinte,
        \DateTimeImmutable $effaceLe,
        int $rapportsConsommes,
        ?\DateTimeImmutable $finEssai,
    ): self {
        return new self($empreinte, $effaceLe, $rapportsConsommes, $finEssai);
    }

    public function empreinte(): string
    {
        return $this->empreinte;
    }

    public function effaceLe(): \DateTimeImmutable
    {
        return $this->effaceLe;
    }

    public function rapportsConsommes(): int
    {
        return $this->rapportsConsommes;
    }

    public function finEssai(): ?\DateTimeImmutable
    {
        return $this->finEssai;
    }
}
