<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

/**
 * État d'un millésime calculé, tel que l'admin doit le voir pour décider (#130).
 *
 * Un millésime archivé n'est **pas** servi : il attend une décision. Les effectifs
 * (calculées / bloquées / en alerte) sont ce sur quoi la décision s'appuie — REG-1
 * fournit le rapport d'écarts, il ne décide pas à la place de l'opérateur.
 */
final readonly class MillesimeEnRevue
{
    public const string PUBLIE = 'publie';
    public const string ABANDONNE = 'abandonne';

    public function __construct(
        private int $millesime,
        private ?string $etat,
        private ?string $decidePar,
        private ?\DateTimeImmutable $decideLe,
        private int $maillesCalculees,
        private int $maillesBloquees,
        private int $maillesEnAlerte,
    ) {
    }

    public function millesime(): int
    {
        return $this->millesime;
    }

    /**
     * `null` tant qu'aucune décision n'a été prise — calculé n'est pas décidé.
     */
    public function etat(): ?string
    {
        return $this->etat;
    }

    public function estEnAttenteDeDecision(): bool
    {
        return null === $this->etat;
    }

    public function estPublie(): bool
    {
        return self::PUBLIE === $this->etat;
    }

    public function decidePar(): ?string
    {
        return $this->decidePar;
    }

    public function decideLe(): ?\DateTimeImmutable
    {
        return $this->decideLe;
    }

    public function maillesCalculees(): int
    {
        return $this->maillesCalculees;
    }

    public function maillesBloquees(): int
    {
        return $this->maillesBloquees;
    }

    public function maillesEnAlerte(): int
    {
        return $this->maillesEnAlerte;
    }
}
