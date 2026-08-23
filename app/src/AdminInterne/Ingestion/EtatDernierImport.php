<?php

declare(strict_types=1);

namespace App\AdminInterne\Ingestion;

use App\AdminInterne\RapportBatch\RapportBatch;

/**
 * État du dernier import d'un département (#46) : la dernière ingestion archivée
 * (#131) relue avec les seuils des portes qui l'ont déjà jugée. L'écran **ne
 * recalcule rien** — il lit la même donnée que le rapport de batch, et se contente
 * de la confronter aux seuils pour dire ce qui mérite un regard.
 *
 * Les seuils sont déclarés ici parce que le contrat A/B (décision D6) interdit à
 * « app » de lire la configuration du pipeline : ce sont les mêmes valeurs que
 * `data` (``appariement_min_pct``, ``fraicheur_max_mois``), déclarées de part et
 * d'autre — même précédent que ``SeuilsSupervision::GAIN_EC_MAX_LOG_DEFAUT``. La
 * porte reste côté pipeline ; ici on n'affiche qu'un signal.
 */
final readonly class EtatDernierImport
{
    /** DAT-3 (#126) : le seuil du jalon J1, borne incluse — 60 % pile passe. */
    private const float APPARIEMENT_MINIMAL = 0.60;

    /** DAT-5 (#128) : borne stricte — à 12 mois pile la source est encore fraîche. */
    private const int FRAICHEUR_MAX_MOIS = 12;

    public function __construct(
        private RapportBatch $rapport,
    ) {
        if (!$rapport->estIngestion()) {
            throw new \InvalidArgumentException("L'état du dernier import se lit sur une ingestion, pas sur un calcul.");
        }
    }

    public function rapport(): RapportBatch
    {
        return $this->rapport;
    }

    public function departement(): string
    {
        return $this->rapport->departement();
    }

    public function millesime(): int
    {
        return $this->rapport->millesime();
    }

    public function importeLe(): \DateTimeImmutable
    {
        return $this->rapport->fin();
    }

    public function mutationsInserees(): int
    {
        return $this->rapport->mutationsInserees() ?? 0;
    }

    public function ventesAppariees(): int
    {
        return $this->rapport->ventesAppariees() ?? 0;
    }

    public function communesTraitees(): int
    {
        return $this->rapport->communesTraitees() ?? 0;
    }

    public function communesSansDonnees(): int
    {
        return $this->rapport->sansDonnees() ?? 0;
    }

    /**
     * @return list<string>
     */
    public function communesEnEchec(): array
    {
        return $this->rapport->echecs();
    }

    public function tauxAppariement(): ?float
    {
        return $this->rapport->tauxAppariement();
    }

    public function mutationLaPlusRecente(): ?\DateTimeImmutable
    {
        return $this->rapport->mutationLaPlusRecente();
    }

    /**
     * Appariement DVF ↔ DPE sous le seuil de DAT-3. Un département sans aucune vente
     * insérée n'a pas de taux : ce n'est pas un appariement effondré, c'est un
     * département vide — le signaler comme tel serait un faux rouge.
     */
    public function appariementInsuffisant(): bool
    {
        $taux = $this->tauxAppariement();

        return null !== $taux && $taux < self::APPARIEMENT_MINIMAL;
    }

    /**
     * Source périmée au sens de DAT-5 : la mutation la plus récente du département
     * date de plus de 12 mois. Une fraîcheur inconnue (ingestion antérieure à DAT-5)
     * n'est pas une source périmée — on ne transforme pas une absence de mesure en
     * verdict.
     */
    public function sourcePerimee(\DateTimeImmutable $maintenant): bool
    {
        $mutation = $this->mutationLaPlusRecente();

        return null !== $mutation && $mutation < $maintenant->modify(\sprintf('-%d months', self::FRAICHEUR_MAX_MOIS));
    }

    public function enAlerte(\DateTimeImmutable $maintenant): bool
    {
        return $this->appariementInsuffisant() || $this->sourcePerimee($maintenant);
    }
}
