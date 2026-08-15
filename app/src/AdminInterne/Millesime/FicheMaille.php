<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

use App\AdminInterne\Coefficient\SeuilsSupervision;

/**
 * Fiche de diagnostic d'une maille : tous ses critères d'évaluation, en clair.
 *
 * Traduit le contrat de sortie du moteur en quelque chose d'interprétable sans
 * connaître le moteur. Trois pièges y sont neutralisés :
 *
 * - les coefficients DPE sont en **logarithme** — ils sont convertis en effet prix
 *   (`exp(coef) − 1`), seuil compris, qui est réglé en log ;
 * - `coef_m2_bati` / `coef_m2_terrain` sont des **élasticités**, jamais des prix
 *   au m² : l'explication le dit, et aucun « € » n'apparaît ;
 * - le R² est un diagnostic **jamais publié** (#23) : présenté sans verdict.
 */
final readonly class FicheMaille
{
    /** Effectif minimal pour qu'une classe DPE soit publiable (S21). */
    private const int VENTES_MIN_PAR_CLASSE = 20;

    /** Effectif minimal d'une maille (INV-4, `min_local_sample`). */
    private const int VENTES_MIN = 30;

    /** Plafond de rayon (INV-3, `rayon_max_km`). */
    private const int RAYON_MAX_KM = 50;

    /** Bornes de l'élasticité du bâti (INV-2). */
    private const float ELASTICITE_BATI_MIN = 0.30;
    private const float ELASTICITE_BATI_MAX = 1.50;

    /** Classes DPE de la meilleure à la pire — l'ordre attendu des effets (INV-1). */
    private const array CLASSES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /**
     * @param list<RubriqueFiche> $rubriques
     */
    private function __construct(
        private string $codeInsee,
        private int $millesime,
        private array $rubriques,
    ) {
    }

    /**
     * @param array<string, mixed> $ligne ligne de `coefficient_maille_historique`
     */
    public static function depuis(array $ligne, SeuilsSupervision $seuils): self
    {
        $rubriques = [
            self::fiabilite($ligne, $seuils),
            self::perimetre($ligne),
            self::effetDpe($ligne, $seuils),
            self::elasticites($ligne),
        ];

        $ecart = self::ecart($ligne);
        if (null !== $ecart) {
            $rubriques[] = $ecart;
        }

        return new self(
            self::texte($ligne['code_insee'] ?? ''),
            self::entier($ligne['millesime'] ?? 0),
            $rubriques,
        );
    }

    public function codeInsee(): string
    {
        return $this->codeInsee;
    }

    public function millesime(): int
    {
        return $this->millesime;
    }

    /**
     * @return list<RubriqueFiche>
     */
    public function rubriques(): array
    {
        return $this->rubriques;
    }

    public function toutRespecte(): bool
    {
        foreach ($this->rubriques as $rubrique) {
            if (!$rubrique->toutRespecte()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function fiabilite(array $ligne, SeuilsSupervision $seuils): RubriqueFiche
    {
        $ventes = self::entier($ligne['n_transactions'] ?? 0);
        $erreur = self::reel($ligne['erreur_mediane'] ?? 0);
        $maximum = $seuils->erreurMedianeMaxPct();

        return new RubriqueFiche('Fiabilité', [
            new Critere(
                'Ventes analysées',
                self::nombre($ventes),
                \sprintf('≥ %d', self::VENTES_MIN),
                $ventes >= self::VENTES_MIN,
                'Nombre de transactions réelles sur lesquelles le calcul s\'appuie.',
            ),
            new Critere(
                'Erreur médiane',
                self::pourcentage($erreur),
                \sprintf('< %s', self::pourcentage($maximum)),
                $erreur < $maximum,
                'Écart typique entre le prix estimé et le prix réellement observé. '
                .'Mesuré en réservant une vente sur cinq pour vérifier la prédiction '
                .'sur des ventes que le calcul n\'a pas vues.',
            ),
            new Critere(
                'Qualité d\'ajustement (R²)',
                self::decimal(self::reel($ligne['r2'] ?? 0)),
                null,
                null,
                'Diagnostic interne, jamais montré au client : un R² élevé ne garantit '
                .'pas une bonne estimation. C\'est l\'erreur médiane qui fait foi.',
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function perimetre(array $ligne): RubriqueFiche
    {
        $rayon = self::entier($ligne['rayon_retenu_km'] ?? 0);
        $alerte = self::texteOuNull($ligne['alerte'] ?? null);

        $criteres = [
            new Critere(
                'Niveau de calcul',
                self::niveau(self::texte($ligne['mode'] ?? 'local')),
                null,
                null,
                'Quand une commune n\'a pas assez de ventes, le calcul s\'élargit à '
                .'son intercommunalité, puis à son département. Plus le niveau est '
                .'large, moins l\'estimation est locale.',
            ),
            new Critere(
                'Rayon retenu',
                0 === $rayon ? 'sans objet (calcul élargi)' : \sprintf('%d km', $rayon),
                \sprintf('≤ %d km', self::RAYON_MAX_KM),
                0 === $rayon || $rayon <= self::RAYON_MAX_KM,
                'Distance autour de la commune dans laquelle les ventes ont été '
                .'cherchées, élargie jusqu\'à en trouver assez.',
            ),
        ];

        if (null !== $alerte) {
            $criteres[] = new Critere(
                'Cohérence avec les communes voisines',
                $alerte,
                'écart < facteur 2',
                false,
                'Une commune limitrophe donne un résultat très différent : possible '
                .'artefact de bord. La maille reste servie, avec un bandeau.',
            );
        }

        return new RubriqueFiche('Périmètre géographique', $criteres);
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function effetDpe(array $ligne, SeuilsSupervision $seuils): RubriqueFiche
    {
        $coefficients = self::json($ligne['coef_dpe'] ?? null);
        $effectifs = self::json($ligne['effectifs_dpe'] ?? null);

        $criteres = [];

        $gain = self::gainEc($coefficients);
        if (null !== $gain) {
            $plafond = exp($seuils->gainEcMaxLog()) - 1;
            $criteres[] = new Critere(
                'Gain E → C',
                self::pourcentageSigne($gain * 100),
                \sprintf('> 0 et ≤ %s', self::pourcentageSigne($plafond * 100)),
                $gain > 0 && $gain <= $plafond,
                'Effet sur le prix du passage de l\'étiquette E à l\'étiquette C. '
                .'Converti depuis l\'échelle logarithmique du modèle : le coefficient '
                .'brut n\'est ni un montant ni un pourcentage.',
            );
        }

        $criteres[] = new Critere(
            'Ordre des classes',
            self::monotone($coefficients) ? 'respecté' : 'rompu',
            'A meilleure que B, … que G',
            self::monotone($coefficients),
            'Une meilleure étiquette doit valoir davantage. Une inversion signale un '
            .'échantillon trop petit ou un effet d\'emplacement qui masque le DPE.',
        );

        if ([] !== $effectifs) {
            $criteres[] = new Critere(
                'Ventes par classe DPE',
                self::effectifs($effectifs),
                \sprintf('≥ %d par classe publiée', self::VENTES_MIN_PAR_CLASSE),
                self::effectifsSuffisants($effectifs),
                'Une classe trop peu représentée donne un effet instable ; elle est '
                .'écartée du calcul plutôt que publiée à tort.',
            );
        }

        return new RubriqueFiche('Effet du DPE', $criteres);
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function elasticites(array $ligne): RubriqueFiche
    {
        $bati = self::reel($ligne['coef_m2_bati'] ?? 0);
        $terrain = self::reel($ligne['coef_m2_terrain'] ?? 0);

        return new RubriqueFiche('Sensibilité aux surfaces', [
            new Critere(
                'Surface bâtie',
                self::decimal($bati),
                \sprintf('entre %s et %s', self::decimal(self::ELASTICITE_BATI_MIN), self::decimal(self::ELASTICITE_BATI_MAX)),
                $bati >= self::ELASTICITE_BATI_MIN && $bati <= self::ELASTICITE_BATI_MAX,
                'Élasticité, et non un prix au m² : 1 % de surface en plus fait varier '
                .'le prix de ce pourcentage-là.',
            ),
            new Critere(
                'Surface de terrain',
                self::decimal($terrain),
                '> 0 et inférieure au bâti',
                $terrain > 0 && $terrain < $bati,
                'Même lecture que ci-dessus. Le terrain doit peser moins que le bâti, '
                .'sinon le modèle confond maison et parcelle.',
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function ecart(array $ligne): ?RubriqueFiche
    {
        $classe = self::texteOuNull($ligne['classe_ecart'] ?? null);
        if (null === $classe) {
            return null;
        }

        $ecart = self::reel($ligne['ecart'] ?? 0);
        $bloquant = 'blocage' === $classe;

        return new RubriqueFiche('Écart au millésime précédent', [
            new Critere(
                self::texteOuNull($ligne['ecart_coefficient'] ?? null) ?? 'coefficient',
                self::pourcentageSigne($ecart * 100),
                '≤ 25 %',
                !$bloquant,
                $bloquant
                    ? 'Écart trop fort d\'un millésime à l\'autre : la maille est écartée '
                      .'de la publication et garde sa valeur précédente, en attendant examen.'
                    : 'Écart notable mais sous le seuil de blocage : la maille est publiée, '
                      .'et signalée pour revue.',
            ),
        ]);
    }

    /**
     * @param array<string, float> $coefficients
     */
    private static function gainEc(array $coefficients): ?float
    {
        if (!isset($coefficients['C'], $coefficients['E'])) {
            return null;
        }

        return exp($coefficients['C'] - $coefficients['E']) - 1;
    }

    /**
     * @param array<string, float> $coefficients
     */
    private static function monotone(array $coefficients): bool
    {
        $precedent = null;
        foreach (self::CLASSES as $classe) {
            if (!isset($coefficients[$classe])) {
                continue;
            }
            if (null !== $precedent && $coefficients[$classe] > $precedent) {
                return false;
            }
            $precedent = $coefficients[$classe];
        }

        return true;
    }

    /**
     * @param array<string, float> $effectifs
     */
    private static function effectifs(array $effectifs): string
    {
        $morceaux = [];
        foreach (self::CLASSES as $classe) {
            if (isset($effectifs[$classe])) {
                $morceaux[] = \sprintf('%s : %d', $classe, (int) $effectifs[$classe]);
            }
        }

        return implode(' · ', $morceaux);
    }

    /**
     * @param array<string, float> $effectifs
     */
    private static function effectifsSuffisants(array $effectifs): bool
    {
        foreach ($effectifs as $nombre) {
            if ((int) $nombre < self::VENTES_MIN_PAR_CLASSE) {
                return false;
            }
        }

        return true;
    }

    private static function niveau(string $mode): string
    {
        return match ($mode) {
            'epci' => 'EPCI (intercommunalité)',
            'departemental' => 'Département',
            default => 'Commune',
        };
    }

    /**
     * @return array<string, float>
     */
    private static function json(mixed $valeur): array
    {
        if (!\is_string($valeur) || '' === $valeur) {
            return [];
        }

        /** @var array<string, mixed> $decode */
        $decode = json_decode($valeur, true, flags: \JSON_THROW_ON_ERROR);

        $nombres = [];
        foreach ($decode as $cle => $nombre) {
            if (is_numeric($nombre)) {
                $nombres[(string) $cle] = (float) $nombre;
            }
        }

        return $nombres;
    }

    private static function nombre(int $valeur): string
    {
        return number_format($valeur, 0, ',', ' ');
    }

    private static function decimal(float $valeur): string
    {
        return number_format($valeur, 2, ',', ' ');
    }

    private static function pourcentage(float $valeur): string
    {
        return number_format($valeur, 1, ',', ' ').' %';
    }

    private static function pourcentageSigne(float $valeur): string
    {
        return ($valeur >= 0 ? '+' : '−').number_format(abs($valeur), 1, ',', ' ').' %';
    }

    private static function texte(mixed $valeur): string
    {
        return \is_string($valeur) ? trim($valeur) : '';
    }

    private static function texteOuNull(mixed $valeur): ?string
    {
        return \is_string($valeur) && '' !== trim($valeur) ? trim($valeur) : null;
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    private static function reel(mixed $valeur): float
    {
        return is_numeric($valeur) ? (float) $valeur : 0.0;
    }
}
