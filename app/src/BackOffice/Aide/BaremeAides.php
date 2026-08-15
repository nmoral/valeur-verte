<?php

declare(strict_types=1);

namespace App\BackOffice\Aide;

use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;

/**
 * Barème d'aides versionné (#26), calé sur MaPrimeRénov' **rénovation d'ampleur** :
 * un taux de prise en charge par catégorie de revenus, appliqué à un plafond de
 * dépenses HT qui dépend du gain de classes DPE.
 *
 * Le parcours « par geste » n'est pas modélisé : l'isolation des murs en est sortie
 * au 01/01/2026 et un projet de décret (CNH du 02/07/2026, non publié au JO à ce
 * jour) en retire l'essentiel des autres gestes. La rénovation d'ampleur est aussi
 * celle qui correspond au produit, qui vend le franchissement de seuil (#27).
 *
 * Taux **et** plafonds sont versionnés par la période de validité : les figer en dur
 * rendrait les rapports non reproductibles au premier changement de barème (§7,
 * décision D4). Modifier un barème crée une version et clôture la précédente ; aucun
 * `UPDATE` destructif.
 */
final readonly class BaremeAides
{
    /**
     * En deçà de deux classes gagnées, la rénovation d'ampleur n'est pas ouverte.
     */
    public const int GAIN_MINIMAL_CLASSES = 2;

    /**
     * Taux de prise en charge en vigueur depuis le 30/09/2025, en pourcentage.
     */
    private const array TAUX_PAR_DEFAUT_POUR_CENT = [
        'TresModeste' => 80,
        'Modeste' => 60,
        'Intermediaire' => 45,
        'Superieur' => 10,
    ];

    /**
     * Plafonds de dépenses éligibles HT, par gain de classes minimal atteint.
     */
    private const array PLAFONDS_PAR_DEFAUT_EUROS = [
        2 => 30000,
        3 => 40000,
    ];

    private const int PLAFOND_ECO_PTZ_EUROS = 50000;

    /**
     * TVA réduite sur les travaux de rénovation énergétique, en pour mille
     * (55 ‰ = 5,5 %) pour rester en entiers comme {@see Montant}.
     */
    private const int TAUX_TVA_POUR_MILLE = 55;

    /**
     * @param array<string, int>  $tauxPourCent    indexé par CategorieRevenu->name
     * @param array<int, Montant> $plafondsParGain indexé par gain de classes, ordre croissant
     */
    private function __construct(
        private PeriodeValidite $periode,
        private array $tauxPourCent,
        private array $plafondsParGain,
        private Montant $plafondEcoPtz,
        private int $tauxTvaPourMille,
    ) {
    }

    public static function parDefautNational(PeriodeValidite $periode): self
    {
        $plafonds = [];
        foreach (self::PLAFONDS_PAR_DEFAUT_EUROS as $gain => $euros) {
            $plafonds[$gain] = Montant::fromEuros($euros);
        }

        return self::depuis(
            $periode,
            self::TAUX_PAR_DEFAUT_POUR_CENT,
            $plafonds,
            Montant::fromEuros(self::PLAFOND_ECO_PTZ_EUROS),
            self::TAUX_TVA_POUR_MILLE,
        );
    }

    /**
     * @param array<string, int>  $tauxPourCent    indexé par CategorieRevenu->name
     * @param array<int, Montant> $plafondsParGain indexé par gain de classes
     */
    public static function depuis(
        PeriodeValidite $periode,
        array $tauxPourCent,
        array $plafondsParGain,
        Montant $plafondEcoPtz,
        int $tauxTvaPourMille,
    ): self {
        foreach (CategorieRevenu::tranches() as $categorie) {
            $taux = $tauxPourCent[$categorie->name] ?? throw new \InvalidArgumentException(\sprintf('Le taux de la catégorie « %s » est absent du barème.', $categorie->name));

            if ($taux < 0 || $taux > 100) {
                throw new \InvalidArgumentException(\sprintf('Le taux de la catégorie « %s » doit être compris entre 0 et 100 %%.', $categorie->name));
            }
        }

        ksort($plafondsParGain);

        return new self($periode, $tauxPourCent, $plafondsParGain, $plafondEcoPtz, $tauxTvaPourMille);
    }

    public function tauxPourCent(CategorieRevenu $categorie): int
    {
        return $this->tauxPourCent[$categorie->name] ?? throw new \InvalidArgumentException('La catégorie « non précisée » n\'est pas une tranche de revenus : le reste à charge en tire une fourchette, le barème ne l\'arbitre pas.');
    }

    public function ouvreDroit(int $gainDeClasses): bool
    {
        return $gainDeClasses >= self::GAIN_MINIMAL_CLASSES;
    }

    public function plafondDepenses(int $gainDeClasses): Montant
    {
        if (!$this->ouvreDroit($gainDeClasses)) {
            throw new \InvalidArgumentException(\sprintf('Un gain de %d classe(s) n\'ouvre pas droit à la rénovation d\'ampleur (minimum %d).', $gainDeClasses, self::GAIN_MINIMAL_CLASSES));
        }

        $plafond = null;
        foreach ($this->plafondsParGain as $gain => $montant) {
            if ($gainDeClasses >= $gain) {
                $plafond = $montant;
            }
        }

        // Garde défensive : le barème couvre toujours le gain minimal, validé à la
        // construction, donc cette branche est inatteignable en pratique.
        return $plafond ?? throw new \OutOfBoundsException(\sprintf('Aucun plafond de dépenses pour un gain de %d classe(s).', $gainDeClasses));
    }

    /**
     * Gains de classes pour lesquels le barème porte un plafond propre.
     * Sert la persistance, qui doit restituer le barème à l'identique.
     *
     * @return list<int>
     */
    public function gainsCouverts(): array
    {
        return array_keys($this->plafondsParGain);
    }

    public function plafondEcoPtz(): Montant
    {
        return $this->plafondEcoPtz;
    }

    public function tauxTvaPourMille(): int
    {
        return $this->tauxTvaPourMille;
    }

    public function periode(): PeriodeValidite
    {
        return $this->periode;
    }

    /**
     * Sérialisation pour le figeage dans l'instantané du rapport (§9.1). La période
     * n'en fait pas partie : ce qui doit être reproductible, ce sont les valeurs
     * appliquées, pas la fenêtre pendant laquelle elles étaient en vigueur.
     *
     * @return array{taux_pour_cent: array<string, int>, plafonds: array<int, int>, plafond_eco_ptz: int, taux_tva_pour_mille: int}
     */
    public function toArray(): array
    {
        $plafonds = [];
        foreach ($this->gainsCouverts() as $gain) {
            $plafonds[$gain] = $this->plafondDepenses($gain)->euros();
        }

        return [
            'taux_pour_cent' => $this->tauxPourCent,
            'plafonds' => $plafonds,
            'plafond_eco_ptz' => $this->plafondEcoPtz->euros(),
            'taux_tva_pour_mille' => $this->tauxTvaPourMille,
        ];
    }

    /**
     * @param array<string, mixed> $donnees
     */
    public static function fromArray(array $donnees, PeriodeValidite $periode): self
    {
        \assert(\is_array($donnees['taux_pour_cent']));
        \assert(\is_array($donnees['plafonds']));
        \assert(\is_int($donnees['plafond_eco_ptz']));
        \assert(\is_int($donnees['taux_tva_pour_mille']));

        /** @var array<string, int> $taux */
        $taux = $donnees['taux_pour_cent'];

        $plafonds = [];
        foreach ($donnees['plafonds'] as $gain => $euros) {
            \assert(\is_int($euros));
            $plafonds[(int) $gain] = Montant::fromEuros($euros);
        }

        return self::depuis(
            $periode,
            $taux,
            $plafonds,
            Montant::fromEuros($donnees['plafond_eco_ptz']),
            $donnees['taux_tva_pour_mille'],
        );
    }
}
