<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

use Doctrine\DBAL\Connection;

/**
 * Lecture de l'état des millésimes (#130) dans le schéma « data », en lecture seule
 * (décision D6). L'app ne fait que consulter : la projection vers la table vive est
 * l'affaire des fonctions SQL exposées par le pipeline.
 */
final readonly class LectureMillesimes
{
    public function __construct(private Connection $connexion)
    {
    }

    /**
     * Millésimes archivés, du plus récent au plus ancien.
     *
     * @return list<MillesimeEnRevue>
     */
    public function tous(): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            "SELECT h.millesime,
                    p.etat,
                    p.decide_par,
                    p.decide_le,
                    COUNT(*) AS calculees,
                    COUNT(*) FILTER (WHERE h.classe_ecart = 'blocage') AS bloquees,
                    COUNT(*) FILTER (WHERE h.classe_ecart = 'alerte') AS en_alerte
             FROM data.coefficient_maille_historique h
             LEFT JOIN data.publication_millesime p ON p.millesime = h.millesime
             GROUP BY h.millesime, p.etat, p.decide_par, p.decide_le
             ORDER BY h.millesime DESC",
        );

        $millesimes = [];
        foreach ($lignes as $ligne) {
            $millesimes[] = new MillesimeEnRevue(
                millesime: self::entier($ligne['millesime']),
                etat: self::texteOuNull($ligne['etat']),
                decidePar: self::texteOuNull($ligne['decide_par']),
                decideLe: self::dateOuNull($ligne['decide_le']),
                maillesCalculees: self::entier($ligne['calculees']),
                maillesBloquees: self::entier($ligne['bloquees']),
                maillesEnAlerte: self::entier($ligne['en_alerte']),
            );
        }

        return $millesimes;
    }

    /**
     * Mailles écartées par REG-1 pour ce millésime, écart le plus fort d'abord.
     *
     * @return list<MailleBloquee>
     */
    public function maillesBloquees(int $millesime): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            "SELECT code_insee, ecart, ecart_coefficient
             FROM data.coefficient_maille_historique
             WHERE millesime = :millesime AND classe_ecart = 'blocage'
             ORDER BY ecart DESC",
            ['millesime' => $millesime],
        );

        $bloquees = [];
        foreach ($lignes as $ligne) {
            $bloquees[] = new MailleBloquee(
                codeInsee: \is_string($ligne['code_insee']) ? trim($ligne['code_insee']) : '',
                ecart: is_numeric($ligne['ecart']) ? (float) $ligne['ecart'] : 0.0,
                coefficient: self::texteOuNull($ligne['ecart_coefficient']) ?? 'inconnu',
            );
        }

        return $bloquees;
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    private static function texteOuNull(mixed $valeur): ?string
    {
        return \is_string($valeur) && '' !== trim($valeur) ? trim($valeur) : null;
    }

    private static function dateOuNull(mixed $valeur): ?\DateTimeImmutable
    {
        return \is_string($valeur) ? new \DateTimeImmutable($valeur) : null;
    }
}
