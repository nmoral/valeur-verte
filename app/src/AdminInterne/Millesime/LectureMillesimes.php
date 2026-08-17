<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

use App\AdminInterne\Coefficient\SeuilsSupervision;
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
        // Une maille débloquée (REG-2) est projetée vers la table vive sous son
        // millésime sans que son `classe_ecart` d'archive change : on l'exclut donc
        // dès qu'elle y figure, sinon elle resterait éternellement « candidate au
        // déblocage » alors qu'elle est déjà servie.
        $lignes = $this->connexion->fetchAllAssociative(
            "SELECT h.code_insee, h.ecart, h.ecart_coefficient
             FROM data.coefficient_maille_historique h
             WHERE h.millesime = :millesime AND h.classe_ecart = 'blocage'
               AND NOT EXISTS (
                   SELECT 1 FROM data.coefficient_maille v
                   WHERE v.code_insee = h.code_insee AND v.millesime = h.millesime
               )
             ORDER BY h.ecart DESC",
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

    /**
     * Fiche de diagnostic d'une maille : tous ses critères d'évaluation en clair.
     * `null` si la commune n'a pas été calculée sous ce millésime.
     */
    public function fiche(string $codeInsee, int $millesime, SeuilsSupervision $seuils): ?FicheMaille
    {
        $ligne = $this->connexion->fetchAssociative(
            'SELECT code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
                    coef_m2_bati, coef_m2_terrain, coef_dpe, effectifs_dpe, mode, palier, alerte,
                    classe_ecart, ecart, ecart_coefficient
             FROM data.coefficient_maille_historique
             WHERE code_insee = :code AND millesime = :millesime',
            ['code' => $codeInsee, 'millesime' => $millesime],
        );

        return false === $ligne ? null : FicheMaille::depuis($ligne, $seuils);
    }

    /**
     * État décidé d'un millésime, ou `null` s'il attend encore une décision.
     */
    public function etat(int $millesime): ?string
    {
        $etat = $this->connexion->fetchOne(
            'SELECT etat FROM data.publication_millesime WHERE millesime = :millesime',
            ['millesime' => $millesime],
        );

        return self::texteOuNull($etat);
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
