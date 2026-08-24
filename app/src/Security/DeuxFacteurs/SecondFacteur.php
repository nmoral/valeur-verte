<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use Doctrine\ORM\Mapping as ORM;

/**
 * Second facteur d'un compte (#312) : le secret partagé avec l'application
 * d'authentification, son état, et la mémoire du dernier pas consommé.
 *
 * Le secret est stocké **en clair**, contrairement au mot de passe et aux codes
 * de secours : il n'est pas comparé mais rejoué — le serveur recalcule le code
 * qu'affiche le téléphone. C'est ce qui impose de le purger d'un dump restauré
 * hors production, sans quoi le secret de l'admin de prod se promènerait sur les
 * postes de développement.
 *
 * Un compte n'a qu'un second facteur à la fois : réenrôler un téléphone remplace
 * le secret plutôt que d'en ajouter un — deux appareils valides dont un seul est
 * connu de son propriétaire, c'est la définition d'une porte dérobée.
 */
#[ORM\Entity(repositoryClass: SecondFacteurRepository::class)]
#[ORM\Table(name: 'second_facteur')]
#[ORM\UniqueConstraint(name: 'uniq_second_facteur_user', columns: ['user_id'])]
class SecondFacteur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(length: 64)]
    private string $secret;

    // Null tant que l'enrôlement n'a pas été confirmé par un code : un secret
    // scanné mais jamais confirmé ne protège rien et ne bloque rien.
    #[ORM\Column(name: 'active_le', nullable: true)]
    private ?\DateTimeImmutable $activeLe = null;

    // Dernier pas de 30 secondes consommé — garde anti-rejeu.
    #[ORM\Column(name: 'dernier_pas', nullable: true)]
    private ?int $dernierPas = null;

    public function __construct(int $userId, string $secret)
    {
        $this->userId = $userId;
        $this->secret = $secret;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function estActif(): bool
    {
        return null !== $this->activeLe;
    }

    public function activer(\DateTimeImmutable $le): void
    {
        $this->activeLe = $le;
    }

    /**
     * Un pas n'est consommable qu'une fois, et jamais en arrière : la tolérance
     * de dérive rendrait sinon la fenêtre précédente à nouveau utilisable.
     */
    public function pasEstConsommable(int $pas): bool
    {
        return null === $this->dernierPas || $pas > $this->dernierPas;
    }

    public function consommerPas(int $pas): void
    {
        $this->dernierPas = $pas;
    }
}
