<?php

declare(strict_types=1);

namespace App\Publication;

use Doctrine\DBAL\Connection;

/**
 * Énumération nationale des EPCI mesurés (#365) pour le sitemap : tous les EPCI
 * portant **au moins une commune mesurée**, en slugs d'URL. Version nationale de
 * {@see AnnuaireEpciDuDepartement} (sans le filtre département). Les hubs EPCI
 * étant toujours indexables (#361 ne gate que les communes), aucun filtrage ici.
 * Conditionné à un millésime publié (sinon aucune page n'existe).
 */
final readonly class EpciMesures
{
    private const SELECT =
        'SELECT DISTINCT ep.code_epci, ep.nom
         FROM data.coefficient_maille cm
         JOIN data.commune_epci e ON e.code_insee = cm.code_insee
         JOIN data.epci ep        ON ep.code_epci = e.code_epci
         ORDER BY ep.nom';

    public function __construct(
        private LectureModelePublie $lecture,
        private Connection $connexion,
    ) {
    }

    /**
     * @return list<string> slugs d'URL des EPCI mesurés, ordonnés par nom
     */
    public function slugs(): array
    {
        if (null === $this->lecture->publication()) {
            return [];
        }

        $slugs = [];
        foreach ($this->connexion->fetchAllAssociative(self::SELECT) as $ligne) {
            $nom = \is_string($ligne['nom']) ? $ligne['nom'] : '';
            $slugs[] = Slug::depuis($nom)->valeur();
        }

        return $slugs;
    }
}
