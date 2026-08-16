<?php

declare(strict_types=1);

namespace App\Publication;

use Doctrine\DBAL\Connection;

/**
 * Lit le schéma « data » en lecture seule (décision D6).
 *
 * Les mailles viennent de la table **vive** ``data.coefficient_maille`` : c'est,
 * par définition, ce qui est servi aux clients. L'historique n'est jamais lu ici —
 * un millésime calculé mais non publié n'a pas à transparaître sur une page publique.
 */
final readonly class LectureModelePublieDbal implements LectureModelePublie
{
    public function __construct(private Connection $connexion)
    {
    }

    public function publication(): ?Publication
    {
        $decision = $this->connexion->fetchAssociative(
            "SELECT millesime, decide_le
             FROM data.publication_millesime
             WHERE etat = 'publie'
             ORDER BY millesime DESC
             LIMIT 1",
        );

        if (false === $decision) {
            return null;
        }

        $millesime = self::entier($decision['millesime']);

        return new Publication(
            millesime: $millesime,
            publieLe: self::horodatage($decision['decide_le']) ?? new \DateTimeImmutable(),
            calculeLe: self::horodatage($this->connexion->fetchOne(
                'SELECT max(calcule_le) FROM data.coefficient_maille WHERE millesime = :millesime',
                ['millesime' => $millesime],
            )),
        );
    }

    public function mailles(): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT code_insee, mode, coef_dpe FROM data.coefficient_maille ORDER BY code_insee',
        );

        $mailles = [];
        foreach ($lignes as $ligne) {
            $mailles[] = new Maille(
                codeInsee: \is_string($ligne['code_insee']) ? trim($ligne['code_insee']) : '',
                mode: \is_string($ligne['mode']) ? $ligne['mode'] : '',
                coefDpe: self::coefficients($ligne['coef_dpe']),
            );
        }

        return $mailles;
    }

    public function communesReferencees(): int
    {
        return self::entier($this->connexion->fetchOne('SELECT count(*) FROM data.commune'));
    }

    /**
     * @return array<string, float>
     */
    private static function coefficients(mixed $json): array
    {
        if (!\is_string($json)) {
            return [];
        }

        $decode = json_decode($json, true);
        if (!\is_array($decode)) {
            return [];
        }

        $coefficients = [];
        foreach ($decode as $classe => $effet) {
            if (is_numeric($effet)) {
                $coefficients[(string) $classe] = (float) $effet;
            }
        }

        return $coefficients;
    }

    private static function horodatage(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || '' === $valeur) {
            return null;
        }

        return new \DateTimeImmutable($valeur);
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }
}
