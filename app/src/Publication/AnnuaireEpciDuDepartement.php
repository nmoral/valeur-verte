<?php

declare(strict_types=1);

namespace App\Publication;

use Doctrine\DBAL\Connection;

/**
 * Annuaire des EPCI d'un département (#364, maillage descendant du hub
 * départemental) : les intercommunalités qui portent **au moins une commune
 * mesurée** du département, résolues en nom + slug d'URL.
 *
 * Lecture ciblée du schéma « data » (décision D6) : une jointure
 * ``coefficient_maille`` ⋈ ``commune`` ⋈ ``commune_epci`` ⋈ ``epci``, filtrée sur
 * le département courant et dédoublonnée par EPCI (un EPCI a plusieurs communes ;
 * il peut aussi chevaucher deux départements — on ne le liste que via ses communes
 * du département demandé). Les hubs EPCI étant toujours indexables (#361 ne gate
 * que les communes), l'annuaire n'applique **aucun** filtre d'indexabilité.
 */
final readonly class AnnuaireEpciDuDepartement
{
    private const SELECT =
        'SELECT DISTINCT ep.code_epci, ep.nom
         FROM data.coefficient_maille cm
         JOIN data.commune c       ON c.code_insee = cm.code_insee
         JOIN data.commune_epci e  ON e.code_insee = cm.code_insee
         JOIN data.epci ep         ON ep.code_epci = e.code_epci
         WHERE c.code_departement = :dept
         ORDER BY ep.nom';

    public function __construct(private Connection $connexion)
    {
    }

    /**
     * @return list<LienEpci> ordonnés par nom
     */
    public function pour(string $codeDepartement): array
    {
        $lignes = $this->connexion->fetchAllAssociative(self::SELECT, ['dept' => $codeDepartement]);

        $liens = [];
        foreach ($lignes as $ligne) {
            $nom = \is_string($ligne['nom']) ? $ligne['nom'] : '';
            $liens[] = new LienEpci($nom, Slug::depuis($nom)->valeur());
        }

        return $liens;
    }
}
