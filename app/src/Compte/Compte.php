<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compte pro : agrégat de facturation, distinct de l'utilisateur. Un compte est
 * rattaché à un tenant (1 pour 1 aujourd'hui) et pourra porter **plusieurs
 * utilisateurs** plus tard (licences additionnelles). Il porte la formule (#61)
 * et la candidature fondateur, d'où découlent les droits d'essai (#108).
 */
#[ORM\Entity(repositoryClass: CompteRepository::class)]
#[ORM\Table(name: 'compte')]
class Compte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $tenantId;

    #[ORM\Column(enumType: Formule::class)]
    private Formule $formule;

    #[ORM\Column(enumType: StatutCandidatureFondateur::class)]
    private StatutCandidatureFondateur $candidatureFondateur;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    /**
     * Date d'envoi de l'email de relance de fin d'essai (#108). Nulle tant qu'il
     * n'a pas été envoyé : garantit l'idempotence de la relance (un seul email).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $relanceEssaiEnvoyeeLe = null;

    /**
     * Date à laquelle le compte s'est désabonné de la relance de fin d'essai
     * (#108). Nulle par défaut ; renseignée, elle inhibe tout envoi de relance.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $relanceEssaiDesabonneeLe = null;

    private function __construct(int $tenantId, \DateTimeImmutable $creeLe)
    {
        $this->tenantId = $tenantId;
        $this->creeLe = $creeLe;
        $this->formule = Formule::Classique;
        $this->candidatureFondateur = StatutCandidatureFondateur::EnAttente;
    }

    public static function creer(int $tenantId, \DateTimeImmutable $creeLe): self
    {
        return new self($tenantId, $creeLe);
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function formule(): Formule
    {
        return $this->formule;
    }

    public function candidatureFondateur(): StatutCandidatureFondateur
    {
        return $this->candidatureFondateur;
    }

    public function creeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /**
     * Validation par l'éditeur (#61) : le compte devient fondateur. Le plafond de
     * comptes fondateurs est vérifié en amont (à la frontière, cf. le service de
     * validation), pas ici où l'agrégat ne connaît pas les autres comptes.
     */
    public function validerCommeFondateur(): void
    {
        $this->formule = Formule::Fondateur;
        $this->candidatureFondateur = StatutCandidatureFondateur::Validee;
    }

    public function refuserFondateur(): void
    {
        $this->formule = Formule::Classique;
        $this->candidatureFondateur = StatutCandidatureFondateur::Refusee;
    }

    public function relanceEnvoyee(): bool
    {
        return null !== $this->relanceEssaiEnvoyeeLe;
    }

    public function marquerRelanceEnvoyee(\DateTimeImmutable $quand): void
    {
        $this->relanceEssaiEnvoyeeLe = $quand;
    }

    public function estDesabonneDeLaRelance(): bool
    {
        return null !== $this->relanceEssaiDesabonneeLe;
    }

    public function seDesabonnerDeLaRelance(\DateTimeImmutable $quand): void
    {
        $this->relanceEssaiDesabonneeLe = $quand;
    }
}
