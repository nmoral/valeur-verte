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
     * Date à laquelle le compte s'est désabonné des emails non transactionnels
     * (#108/#109). Nulle par défaut ; renseignée, elle inhibe tout email non
     * transactionnel (relance de fin d'essai, relance après premier rapport…).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailsNonTransactionnelsDesabonneLe = null;

    /**
     * Date d'envoi de la relance conversationnelle après le premier rapport
     * (#109). Nulle tant qu'elle n'a pas été envoyée : garantit qu'un seul email
     * personnel part, sur l'événement « premier rapport », jamais deux.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailPremierRapportEnvoyeLe = null;

    /**
     * Date de suspension du compte par l'admin (#47). Nulle = actif ; renseignée,
     * le compte est suspendu : la **session reste ouverte et la lecture conservée**,
     * mais toute écriture est fermée (#322) — production et modification de rapport,
     * configuration (marque, bouquets, page publique). Un bandeau l'explique sur
     * l'espace pro. Réversible via reactiver().
     */
    #[ORM\Column(name: 'suspendu_le', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suspenduLe = null;

    /**
     * Prolongation d'essai accordée par l'admin (#47) : la fin d'essai est repoussée
     * au moins jusqu'à cette date. Nulle = pas de prolongation.
     */
    #[ORM\Column(name: 'essai_prolonge_jusquau', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $essaiProlongeJusquau = null;

    /**
     * Date de la demande d'effacement du compte (#308, RGPD droit à l'effacement).
     * Nulle = pas de demande ; renseignée, le compte est en attente d'effacement
     * définitif, qui n'intervient qu'après le délai de grâce (une suppression
     * accidentelle ne se rattrape pas). Réversible via annulerSuppression() tant
     * que l'effacement n'a pas été finalisé. La date porte le point de départ du
     * délai de grâce : demanderSuppression() ne la recule pas.
     */
    #[ORM\Column(name: 'suppression_demandee_le', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suppressionDemandeeLe = null;

    /**
     * Date de neutralisation de l'essai (#332). Nulle = essai normal ; renseignée,
     * le compte naît **sans essai** — c'est le compte recréé après un effacement
     * (#308) dont l'adresse est déjà passée par la porte : lui rendre un second
     * essai serait le contournement le plus propre. La date porte le moment de la
     * neutralisation (à la recréation), pas la fin d'essai héritée.
     */
    #[ORM\Column(name: 'essai_neutralise_le', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $essaiNeutraliseLe = null;

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

    public function estDesabonneDesEmails(): bool
    {
        return null !== $this->emailsNonTransactionnelsDesabonneLe;
    }

    public function seDesabonnerDesEmails(\DateTimeImmutable $quand): void
    {
        $this->emailsNonTransactionnelsDesabonneLe = $quand;
    }

    public function emailPremierRapportEnvoye(): bool
    {
        return null !== $this->emailPremierRapportEnvoyeLe;
    }

    public function marquerEmailPremierRapportEnvoye(\DateTimeImmutable $quand): void
    {
        $this->emailPremierRapportEnvoyeLe = $quand;
    }

    public function estSuspendu(): bool
    {
        return null !== $this->suspenduLe;
    }

    public function suspenduLe(): ?\DateTimeImmutable
    {
        return $this->suspenduLe;
    }

    public function suspendre(\DateTimeImmutable $quand): void
    {
        $this->suspenduLe ??= $quand;
    }

    public function reactiver(): void
    {
        $this->suspenduLe = null;
    }

    public function essaiProlongeJusquau(): ?\DateTimeImmutable
    {
        return $this->essaiProlongeJusquau;
    }

    /**
     * Repousse la fin d'essai : ne recule jamais une prolongation déjà accordée.
     */
    public function prolongerEssaiJusqua(\DateTimeImmutable $date): void
    {
        if (null === $this->essaiProlongeJusquau || $date > $this->essaiProlongeJusquau) {
            $this->essaiProlongeJusquau = $date;
        }
    }

    public function estEnAttenteDeSuppression(): bool
    {
        return null !== $this->suppressionDemandeeLe;
    }

    public function suppressionDemandeeLe(): ?\DateTimeImmutable
    {
        return $this->suppressionDemandeeLe;
    }

    /**
     * Enregistre une demande d'effacement. Idempotent sur la première date : une
     * demande déjà posée n'est pas repoussée, le délai de grâce court depuis le
     * premier appel.
     */
    public function demanderSuppression(\DateTimeImmutable $quand): void
    {
        $this->suppressionDemandeeLe ??= $quand;
    }

    public function annulerSuppression(): void
    {
        $this->suppressionDemandeeLe = null;
    }

    public function essaiNeutraliseLe(): ?\DateTimeImmutable
    {
        return $this->essaiNeutraliseLe;
    }

    /**
     * Neutralise l'essai (#332). Idempotent sur la première date : un compte déjà
     * neutralisé ne repousse pas sa date.
     */
    public function neutraliserEssai(\DateTimeImmutable $quand): void
    {
        $this->essaiNeutraliseLe ??= $quand;
    }

    /**
     * État d'essai du compte à un instant donné (#108), en tenant compte d'une
     * neutralisation éventuelle (#332). Le compte portant sa propre neutralisation,
     * tous les sites qui lisent l'essai passent par ici plutôt que par
     * {@see EtatEssai::calculer} directement, pour ne jamais l'oublier.
     */
    public function etatEssai(ParametresFormule $parametres, int $rapportsConsommes, \DateTimeImmutable $maintenant): EtatEssai
    {
        return EtatEssai::calculer($rapportsConsommes, $parametres, $this->creeLe, $maintenant, $this->essaiNeutraliseLe);
    }
}
