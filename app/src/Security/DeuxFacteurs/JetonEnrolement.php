<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use Doctrine\ORM\Mapping as ORM;

/**
 * Lien d'enrôlement à usage unique (#312), émis en ligne de commande sur le VPS.
 * Même modèle que la réinitialisation de mot de passe (#194) : jeton brut dans
 * l'URL, empreinte seule en base, expirant et consommable une fois.
 *
 * Il est consommé par la **confirmation**, pas par l'affichage : l'admin doit
 * pouvoir rafraîchir la page, se tromper de code, revenir — sans repasser par le
 * serveur.
 */
#[ORM\Entity(repositoryClass: JetonEnrolementRepository::class)]
#[ORM\Table(name: 'jeton_enrolement_second_facteur')]
#[ORM\UniqueConstraint(name: 'uniq_jeton_enrolement_empreinte', columns: ['empreinte'])]
#[ORM\Index(name: 'idx_jeton_enrolement_user', columns: ['user_id'])]
class JetonEnrolement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column(name: 'expire_le')]
    private \DateTimeImmutable $expireLe;

    #[ORM\Column(name: 'utilise_le', nullable: true)]
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

    public function estUtilisable(\DateTimeImmutable $maintenant): bool
    {
        return null === $this->utiliseLe && $maintenant < $this->expireLe;
    }

    public function marquerUtilise(\DateTimeImmutable $maintenant): void
    {
        $this->utiliseLe = $maintenant;
    }
}
