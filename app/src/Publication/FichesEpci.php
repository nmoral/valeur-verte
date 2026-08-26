<?php

declare(strict_types=1);

namespace App\Publication;

use App\BackOffice\Restitution\PorteeEstimation;
use Doctrine\DBAL\Connection;

/**
 * Lecture ciblée du modèle publié pour le hub EPCI (#363), analogue EPCI de
 * {@see FichesDepartementales}. Une seule requête jointe
 * (``coefficient_maille`` ⋈ ``commune`` ⋈ ``commune_epci``) sert **à la fois**
 * l'agrégat ({@see MetriqueEpci}) et l'annuaire des communes : le hub couvre
 * quelques dizaines de communes, un balayage de toutes les mailles vives (comme le
 * hub départemental) serait inutilement large.
 *
 * Décision produit (comme le hub #337) : la page n'existe que si un millésime est
 * **publié**, que l'EPCI est au référentiel des noms (``data.epci`` #366) **et**
 * qu'au moins une commune membre porte une maille servie — sinon ``null`` (le
 * contrôleur répond 404, jamais une page vide). L'indexabilité de chaque commune
 * (#361) est dérivée en lot ici (``mode`` + rayon + ``poids_pooling``) : l'annuaire
 * n'ouvre un lien que vers les communes indexables.
 */
final readonly class FichesEpci
{
    private const SELECT =
        'SELECT c.code_insee, c.nom, c.code_departement, cm.mode, cm.coef_dpe,
                cm.rayon_retenu_km, cm.poids_pooling
         FROM data.coefficient_maille cm
         JOIN data.commune c ON c.code_insee = cm.code_insee
         JOIN data.commune_epci e ON e.code_insee = cm.code_insee
         WHERE e.code_epci = :epci
         ORDER BY c.nom';

    public function __construct(
        private LectureModelePublie $lecture,
        private Connection $connexion,
        private RepertoireEpci $epcis,
        private RepertoireDepartements $departements,
    ) {
    }

    public function pour(string $codeEpci): ?FicheEpci
    {
        $publication = $this->lecture->publication();
        if (null === $publication) {
            return null;
        }

        $epci = $this->epcis->pour($codeEpci);
        if (null === $epci) {
            return null;
        }

        $lignes = $this->connexion->fetchAllAssociative(self::SELECT, ['epci' => $codeEpci]);
        if ([] === $lignes) {
            return null;
        }

        $mailles = [];
        $annuaire = [];
        foreach ($lignes as $ligne) {
            $codeInsee = \is_string($ligne['code_insee']) ? trim($ligne['code_insee']) : '';
            $nom = \is_string($ligne['nom']) ? $ligne['nom'] : '';
            $mode = \is_string($ligne['mode']) ? $ligne['mode'] : '';
            $codeDepartement = \is_string($ligne['code_departement']) ? trim($ligne['code_departement']) : '';

            $mailles[] = new Maille($codeInsee, $mode, self::coefficients($ligne['coef_dpe']));

            $annuaire[] = new LienCommune(
                nom: $nom,
                departementSlug: $this->departements->slug($codeDepartement) ?? '',
                communeSlug: Slug::depuis($nom)->valeur(),
                indexable: self::indexabilite($mode, $ligne['rayon_retenu_km'], $ligne['poids_pooling'])->estIndexable(),
            );
        }

        return new FicheEpci(
            millesime: $publication->millesime,
            publieLe: $publication->publieLe,
            calculeLe: $publication->calculeLe,
            epci: MetriqueEpci::pour($codeEpci, $epci->nom, $mailles),
            annuaire: $annuaire,
        );
    }

    private static function indexabilite(string $mode, mixed $rayon, mixed $poidsPooling): IndexabiliteCommune
    {
        $rayonKm = is_numeric($rayon) ? (int) $rayon : 0;

        return IndexabiliteCommune::depuis(
            PorteeEstimation::depuis($mode, 0 === $rayonKm ? null : $rayonKm),
            is_numeric($poidsPooling) ? (float) $poidsPooling : null,
        );
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
}
