<?php

declare(strict_types=1);

namespace App\AdminInterne\RapportBatch;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Lit les rapports de batch archivés (#131) dans ``data.rapport_batch`` (schéma
 * « data », lecture seule pour « app »). La colonne ``phase`` décide de la fabrique
 * à employer : les métriques de l'autre phase sont ``NULL`` en base et absentes de
 * l'objet. « Mailles publiées » n'est pas lu ici — cela relève de la publication
 * manuelle (REG-2), jointe à l'affichage.
 */
final readonly class LectureRapportsBatch
{
    private const COLONNES = 'id, phase, departement, millesime, debut, fin, '
        .'communes_traitees, communes_referencees, mutations_inserees, ventes_appariees, '
        .'contours_charges, epci_charges, sans_donnees, '
        .'communes_calculees, refus, rejets_invariants, alertes_bord, inversions_dpe, '
        .'ecarts_blocages, ecarts_alertes, ordre_canonique, echecs';

    public function __construct(
        private Connection $connexion,
    ) {
    }

    /**
     * @return list<RapportBatch>
     */
    public function recents(int $plafond): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT '.self::COLONNES.' FROM data.rapport_batch ORDER BY debut DESC, id DESC LIMIT :plafond',
            ['plafond' => $plafond],
            ['plafond' => ParameterType::INTEGER],
        );

        return array_map(self::depuisLigne(...), $lignes);
    }

    public function parId(int $id): ?RapportBatch
    {
        $ligne = $this->connexion->fetchAssociative(
            'SELECT '.self::COLONNES.' FROM data.rapport_batch WHERE id = :id',
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );

        return false === $ligne ? null : self::depuisLigne($ligne);
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function depuisLigne(array $ligne): RapportBatch
    {
        $id = self::entier($ligne['id']);
        $departement = \is_string($ligne['departement']) ? trim($ligne['departement']) : '';
        $millesime = self::entier($ligne['millesime']);
        $debut = self::horodatage($ligne['debut']);
        $fin = self::horodatage($ligne['fin']);
        $echecs = self::codes($ligne['echecs']);

        if ('calcul' === $ligne['phase']) {
            return RapportBatch::calcul(
                id: $id,
                departement: $departement,
                millesime: $millesime,
                debut: $debut,
                fin: $fin,
                communesCalculees: self::entier($ligne['communes_calculees']),
                refus: self::entier($ligne['refus']),
                rejetsInvariants: self::entier($ligne['rejets_invariants']),
                alertesBord: self::entier($ligne['alertes_bord']),
                inversionsDpe: self::entier($ligne['inversions_dpe']),
                ecartsBlocages: self::entier($ligne['ecarts_blocages']),
                ecartsAlertes: self::entier($ligne['ecarts_alertes']),
                ordreCanonique: \is_string($ligne['ordre_canonique']) ? $ligne['ordre_canonique'] : '',
                echecs: $echecs,
            );
        }

        return RapportBatch::ingestion(
            id: $id,
            departement: $departement,
            millesime: $millesime,
            debut: $debut,
            fin: $fin,
            communesTraitees: self::entier($ligne['communes_traitees']),
            mutationsInserees: self::entier($ligne['mutations_inserees']),
            ventesAppariees: self::entier($ligne['ventes_appariees']),
            communesReferencees: self::entier($ligne['communes_referencees']),
            contoursCharges: self::entier($ligne['contours_charges']),
            epciCharges: self::entier($ligne['epci_charges']),
            sansDonnees: self::entier($ligne['sans_donnees']),
            echecs: $echecs,
        );
    }

    private static function horodatage(mixed $valeur): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\is_string($valeur) ? $valeur : 'now');
    }

    /**
     * @return list<string>
     */
    private static function codes(mixed $json): array
    {
        if (!\is_string($json)) {
            return [];
        }

        $decode = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decode)) {
            return [];
        }

        // Les codes INSEE en échec sont écrits comme des chaînes (côté data,
        // ``list[str]``) : on ne garde que les chaînes, sans caster l'inattendu.
        return array_values(array_filter($decode, \is_string(...)));
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }
}
