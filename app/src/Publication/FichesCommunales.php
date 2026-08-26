<?php

declare(strict_types=1);

namespace App\Publication;

use App\BackOffice\Estimation\FournisseurPrixMaille;
use App\BackOffice\Restitution\PalierFiabilite;
use Doctrine\DBAL\Connection;

/**
 * Lecture ciblée du modèle publié pour la fiche d'une commune (#360, SEO Vague 2
 * #338), analogue commune de {@see FichesDepartementales}.
 *
 * Décision produit (REG-2, #337) : la fiche n'existe que si un millésime est
 * **publié** et que la commune porte une maille servie (présente dans la table
 * vive ``data.coefficient_maille``). ``pour()`` renvoie ``null`` dans les deux cas —
 * le contrôleur (#362) répond alors 404, jamais une page vide. Contrairement au
 * hub départemental qui balaie toutes les mailles, on ne lit ici que **la** maille
 * de la commune (page programmatique appelée en masse).
 */
final readonly class FichesCommunales
{
    public function __construct(
        private Connection $connexion,
        private LectureModelePublie $lecture,
        private FournisseurPrixMaille $prix,
    ) {
    }

    public function pour(Commune $commune): ?FicheCommune
    {
        $publication = $this->lecture->publication();
        if (null === $publication) {
            return null;
        }

        $ligne = $this->connexion->fetchAssociative(
            'SELECT rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe, mode, palier, poids_pooling
             FROM data.coefficient_maille
             WHERE code_insee = :code',
            ['code' => $commune->codeInsee],
        );
        if (false === $ligne) {
            return null;
        }

        $rayon = self::entier($ligne['rayon_retenu_km']);

        return FicheCommune::depuis(
            codeInsee: $commune->codeInsee,
            nom: $commune->nom,
            millesime: $publication->millesime,
            calculeLe: $publication->calculeLe,
            maille: new Maille(
                codeInsee: $commune->codeInsee,
                mode: \is_string($ligne['mode']) ? $ligne['mode'] : '',
                coefDpe: self::coefficients($ligne['coef_dpe']),
            ),
            n: self::entier($ligne['n_transactions']),
            rayonKm: 0 === $rayon ? null : $rayon,
            erreurMediane: is_numeric($ligne['erreur_mediane']) ? (float) $ligne['erreur_mediane'] : 0.0,
            prixM2Median: $this->prix->prixM2Median($commune->codeInsee),
            palier: PalierFiabilite::depuis(\is_string($ligne['palier']) ? $ligne['palier'] : null),
            poidsPooling: is_numeric($ligne['poids_pooling']) ? (float) $ligne['poids_pooling'] : null,
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

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }
}
