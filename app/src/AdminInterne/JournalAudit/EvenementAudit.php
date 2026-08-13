<?php

declare(strict_types=1);

namespace App\AdminInterne\JournalAudit;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement inscrit au journal d'audit de l'admin interne (#50) : qui a fait
 * quoi, sur quelle cible, quand. Entité append-only, immuable une fois créée ;
 * l'horodatage est fourni à la création pour rester reproductible.
 */
#[ORM\Entity(repositoryClass: JournalAuditDoctrine::class)]
#[ORM\Table(name: 'journal_audit')]
#[ORM\Index(name: 'idx_journal_audit_survenu_le', columns: ['survenu_le'])]
class EvenementAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, mixed> $contexte
     */
    public function __construct(
        #[ORM\Column(length: 255)]
        private readonly string $acteur,
        #[ORM\Column(length: 128)]
        private readonly string $action,
        #[ORM\Column(length: 255)]
        private readonly string $cible,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $contexte,
        #[ORM\Column(name: 'survenu_le', type: Types::DATETIME_IMMUTABLE)]
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
