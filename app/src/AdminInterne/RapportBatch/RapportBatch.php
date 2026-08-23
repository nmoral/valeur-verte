<?php

declare(strict_types=1);

namespace App\AdminInterne\RapportBatch;

/**
 * Un rapport de batch archivé (#131), tel que l'admin le lit dans
 * ``data.rapport_batch``. Une exécution = un rapport, d'une seule phase : les
 * métriques de l'autre phase sont ``null``. La durée et le taux d'appariement sont
 * dérivés (jamais stockés en double), et « mailles publiées » n'est pas ici — cela
 * relève de la publication manuelle (REG-2).
 */
final readonly class RapportBatch
{
    private const PHASE_INGESTION = 'ingestion';
    private const PHASE_CALCUL = 'calcul';

    /**
     * @param list<string> $echecs
     */
    private function __construct(
        private string $phase,
        private int $id,
        private string $departement,
        private int $millesime,
        private \DateTimeImmutable $debut,
        private \DateTimeImmutable $fin,
        private array $echecs,
        private ?int $communesTraitees = null,
        private ?int $mutationsInserees = null,
        private ?int $ventesAppariees = null,
        private ?int $communesReferencees = null,
        private ?int $contoursCharges = null,
        private ?int $epciCharges = null,
        private ?int $sansDonnees = null,
        private ?int $communesCalculees = null,
        private ?int $refus = null,
        private ?int $rejetsInvariants = null,
        private ?int $alertesBord = null,
        private ?int $inversionsDpe = null,
        private ?int $ecartsBlocages = null,
        private ?int $ecartsAlertes = null,
        private ?string $ordreCanonique = null,
        private ?\DateTimeImmutable $mutationLaPlusRecente = null,
    ) {
    }

    /**
     * @param list<string> $echecs
     */
    public static function ingestion(
        int $id,
        string $departement,
        int $millesime,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        int $communesTraitees,
        int $mutationsInserees,
        int $ventesAppariees,
        int $communesReferencees,
        int $contoursCharges,
        int $epciCharges,
        int $sansDonnees,
        array $echecs,
        ?\DateTimeImmutable $mutationLaPlusRecente = null,
    ): self {
        return new self(
            phase: self::PHASE_INGESTION,
            id: $id,
            departement: $departement,
            millesime: $millesime,
            debut: $debut,
            fin: $fin,
            echecs: $echecs,
            communesTraitees: $communesTraitees,
            mutationsInserees: $mutationsInserees,
            ventesAppariees: $ventesAppariees,
            communesReferencees: $communesReferencees,
            contoursCharges: $contoursCharges,
            epciCharges: $epciCharges,
            sansDonnees: $sansDonnees,
            mutationLaPlusRecente: $mutationLaPlusRecente,
        );
    }

    /**
     * @param list<string> $echecs
     */
    public static function calcul(
        int $id,
        string $departement,
        int $millesime,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        int $communesCalculees,
        int $refus,
        int $rejetsInvariants,
        int $alertesBord,
        int $inversionsDpe,
        int $ecartsBlocages,
        int $ecartsAlertes,
        string $ordreCanonique,
        array $echecs,
    ): self {
        return new self(
            phase: self::PHASE_CALCUL,
            id: $id,
            departement: $departement,
            millesime: $millesime,
            debut: $debut,
            fin: $fin,
            echecs: $echecs,
            communesCalculees: $communesCalculees,
            refus: $refus,
            rejetsInvariants: $rejetsInvariants,
            alertesBord: $alertesBord,
            inversionsDpe: $inversionsDpe,
            ecartsBlocages: $ecartsBlocages,
            ecartsAlertes: $ecartsAlertes,
            ordreCanonique: $ordreCanonique,
        );
    }

    public function estIngestion(): bool
    {
        return self::PHASE_INGESTION === $this->phase;
    }

    public function estCalcul(): bool
    {
        return self::PHASE_CALCUL === $this->phase;
    }

    public function dureeSecondes(): int
    {
        return $this->fin->getTimestamp() - $this->debut->getTimestamp();
    }

    /**
     * Part des ventes insérées appariées à un DPE (DAT-3). Nulle hors ingestion ou
     * sans aucune vente insérée — l'absence d'appariement n'est pas un taux de 0/0.
     */
    public function tauxAppariement(): ?float
    {
        if (null === $this->mutationsInserees || 0 === $this->mutationsInserees) {
            return null;
        }

        return ($this->ventesAppariees ?? 0) / $this->mutationsInserees;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function departement(): string
    {
        return $this->departement;
    }

    public function millesime(): int
    {
        return $this->millesime;
    }

    public function debut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function fin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    /**
     * @return list<string>
     */
    public function echecs(): array
    {
        return $this->echecs;
    }

    public function communesTraitees(): ?int
    {
        return $this->communesTraitees;
    }

    public function mutationsInserees(): ?int
    {
        return $this->mutationsInserees;
    }

    public function ventesAppariees(): ?int
    {
        return $this->ventesAppariees;
    }

    public function communesReferencees(): ?int
    {
        return $this->communesReferencees;
    }

    public function contoursCharges(): ?int
    {
        return $this->contoursCharges;
    }

    public function epciCharges(): ?int
    {
        return $this->epciCharges;
    }

    public function sansDonnees(): ?int
    {
        return $this->sansDonnees;
    }

    /**
     * Date de mutation la plus récente du département à l'ingestion (DAT-5, #128) :
     * la source de vérité de la fraîcheur des sources. Nulle hors ingestion, et nulle
     * pour une ingestion antérieure à DAT-5.
     */
    public function mutationLaPlusRecente(): ?\DateTimeImmutable
    {
        return $this->mutationLaPlusRecente;
    }

    public function communesCalculees(): ?int
    {
        return $this->communesCalculees;
    }

    public function refus(): ?int
    {
        return $this->refus;
    }

    public function rejetsInvariants(): ?int
    {
        return $this->rejetsInvariants;
    }

    public function alertesBord(): ?int
    {
        return $this->alertesBord;
    }

    public function inversionsDpe(): ?int
    {
        return $this->inversionsDpe;
    }

    public function ecartsBlocages(): ?int
    {
        return $this->ecartsBlocages;
    }

    public function ecartsAlertes(): ?int
    {
        return $this->ecartsAlertes;
    }

    public function ordreCanonique(): ?string
    {
        return $this->ordreCanonique;
    }
}
