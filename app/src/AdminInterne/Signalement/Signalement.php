<?php

declare(strict_types=1);

namespace App\AdminInterne\Signalement;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Retour « ce chiffre me semble faux » (#49) : un pro signale une estimation
 * douteuse avec son contexte complet (adresse, saisies, n, rayon…). Meilleure
 * source de calibration sur les premiers mois. Le contenu du signalement est
 * append-only ; seul l'état de traitement (traité / non-traité) est annotable,
 * pour que l'admin puisse écarter en masse les retours déjà instruits (#49).
 */
#[ORM\Entity(repositoryClass: SignalementRepository::class)]
#[ORM\Table(name: 'signalement')]
#[ORM\Index(name: 'idx_signalement_signale_le', columns: ['signale_le'])]
class Signalement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private readonly string $adresse;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private readonly array $contexte;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private readonly ?string $message;

    #[ORM\Column(name: 'signale_le', type: Types::DATETIME_IMMUTABLE)]
    private readonly \DateTimeImmutable $signaleLe;

    #[ORM\Column(name: 'traite_le', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $traiteLe = null;

    /**
     * @param array<string, mixed> $contexte
     */
    private function __construct(string $adresse, array $contexte, ?string $message, \DateTimeImmutable $signaleLe)
    {
        if ('' === trim($adresse)) {
            throw new \InvalidArgumentException("L'adresse d'un signalement ne peut pas être vide.");
        }

        $this->adresse = $adresse;
        $this->contexte = $contexte;
        $this->message = $message;
        $this->signaleLe = $signaleLe;
    }

    /**
     * @param array<string, mixed> $contexte
     */
    public static function creer(string $adresse, array $contexte, ?string $message, \DateTimeImmutable $signaleLe): self
    {
        $message = null !== $message && '' !== trim($message) ? $message : null;

        return new self($adresse, $contexte, $message, $signaleLe);
    }

    public function adresse(): string
    {
        return $this->adresse;
    }

    /**
     * @return array<string, mixed>
     */
    public function contexte(): array
    {
        return $this->contexte;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function signaleLe(): \DateTimeImmutable
    {
        return $this->signaleLe;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function estTraite(): bool
    {
        return null !== $this->traiteLe;
    }

    public function traiteLe(): ?\DateTimeImmutable
    {
        return $this->traiteLe;
    }

    public function marquerTraite(\DateTimeImmutable $quand): void
    {
        // Annotation append-only : le premier traitement fait foi.
        $this->traiteLe ??= $quand;
    }
}
