<?php

declare(strict_types=1);

namespace App\AdminInterne\JournalAudit;

/**
 * Événement inscrit au journal d'audit de l'admin interne (#50) : qui a fait
 * quoi, sur quelle cible, quand. Objet-valeur immuable ; l'horodatage est
 * fourni à la création pour rester reproductible.
 */
final class EvenementAudit
{
    /**
     * @param array<string, mixed> $contexte
     */
    public function __construct(
        private readonly string $acteur,
        private readonly string $action,
        private readonly string $cible,
        private readonly array $contexte,
        private readonly \DateTimeImmutable $survenuLe,
    ) {
        if ('' === trim($this->action)) {
            throw new \InvalidArgumentException("L'action d'un événement d'audit ne peut pas être vide.");
        }
    }

    public function acteur(): string
    {
        return $this->acteur;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function cible(): string
    {
        return $this->cible;
    }

    /**
     * @return array<string, mixed>
     */
    public function contexte(): array
    {
        return $this->contexte;
    }

    public function survenuLe(): \DateTimeImmutable
    {
        return $this->survenuLe;
    }
}
