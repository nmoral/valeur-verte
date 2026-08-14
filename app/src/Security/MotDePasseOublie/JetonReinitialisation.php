<?php

declare(strict_types=1);

namespace App\Security\MotDePasseOublie;

use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton de réinitialisation de mot de passe (#194, AUTH-1). Contrairement au
 * désabonnement (HMAC stateless, idempotent), la réinitialisation exige un jeton
 * **à usage unique** et **expirant** : sa validité dépend donc de l'état
 * (consommé ou non) et de l'horloge. On ne stocke jamais le jeton brut, seulement
 * son **empreinte** (SHA-256) — une fuite de la base ne permet pas de réinitialiser.
 */
#[ORM\Entity(repositoryClass: JetonReinitialisationRepository::class)]
#[ORM\Table(name: 'jeton_reinitialisation_mot_de_passe')]
#[ORM\UniqueConstraint(name: 'uniq_jeton_reinit_empreinte', columns: ['empreinte'])]
#[ORM\Index(name: 'idx_jeton_reinit_user', columns: ['user_id'])]
class JetonReinitialisation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column]
    private \DateTimeImmutable $expireLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $utiliseLe = null;

    public function __construct(int $userId, string $empreinte, \DateTimeImmutable $expireLe)
    {
        $this->userId = $userId;
        $this->empreinte = $empreinte;
        $this->expireLe = $expireLe;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function empreinte(): string
    {
        return $this->empreinte;
    }

    public function estUtilisable(\DateTimeImmutable $maintenant): bool
    {
        return null === $this->utiliseLe && $maintenant < $this->expireLe;
    }

    public function marquerUtilise(\DateTimeImmutable $maintenant): void
    {
        $this->utiliseLe = $maintenant;
    }
}
