<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Candidature fondateur à traiter (#61), vue éditeur : de quoi identifier et
 * contacter le candidat avant de valider — tenant, email du compte, date d'entrée.
 */
final readonly class CandidatureEnAttente
{
    public function __construct(
        private int $tenantId,
        private ?string $email,
        private \DateTimeImmutable $creeLe,
    ) {
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function creeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
