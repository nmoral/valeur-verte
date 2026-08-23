<?php

declare(strict_types=1);

namespace App\Security\ChangementEmail;

use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de changement d'adresse email (#310). Reprend le modèle du jeton de
 * réinitialisation (#194) — **usage unique**, **expirant**, empreinte SHA-256 en
 * base et jeton brut dans le lien — avec une différence essentielle : la demande
 * porte **l'adresse visée**. La bascule n'a lieu qu'au clic sur le lien envoyé à
 * cette adresse : une faute de frappe ne verrouille donc pas le compte, puisque
 * l'adresse fautive n'est jamais posée sur le compte tant que personne n'a prouvé
 * qu'il relevait cette boîte.
 */
#[ORM\Entity(repositoryClass: DemandeChangementEmailRepository::class)]
#[ORM\Table(name: 'demande_changement_email')]
#[ORM\UniqueConstraint(name: 'uniq_demande_changement_email_empreinte', columns: ['empreinte'])]
#[ORM\Index(name: 'idx_demande_changement_email_user', columns: ['user_id'])]
class DemandeChangementEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(length: 180)]
    private string $nouvelEmail;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column]
    private \DateTimeImmutable $expireLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmeeLe = null;

    public function __construct(
        int $userId,
        string $nouvelEmail,
        string $empreinte,
        \DateTimeImmutable $expireLe,
    ) {
        $this->userId = $userId;
        $this->nouvelEmail = $nouvelEmail;
        $this->empreinte = $empreinte;
        $this->expireLe = $expireLe;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function nouvelEmail(): string
    {
        return $this->nouvelEmail;
    }

    public function empreinte(): string
    {
        return $this->empreinte;
    }

    public function estConfirmable(\DateTimeImmutable $maintenant): bool
    {
        return null === $this->confirmeeLe && $maintenant < $this->expireLe;
    }

    public function marquerConfirmee(\DateTimeImmutable $maintenant): void
    {
        $this->confirmeeLe = $maintenant;
    }
}
