<?php

declare(strict_types=1);

namespace App\Publication;

use App\BackOffice\Restitution\PorteeEstimation;
use Doctrine\DBAL\Connection;

/**
 * Énumération nationale des communes **indexables** (#365) pour le sitemap. Balaie
 * en **une seule passe** ``data.coefficient_maille`` ⋈ ``data.commune`` (34k+
 * communes ; jamais commune par commune) et ne retient que celles jugées
 * ``Indexable`` par le gating #361 — les mailles en repli (EPCI/départemental) ou
 * fortement rapprochées (``poids_pooling`` < 0.5) restent crawlables mais **hors
 * sitemap**, exactement comme leur meta robots.
 *
 * Rend les slugs département + commune qui reconstruisent l'URL
 * ``/valeur-verte/{departement}/{commune}``. Conditionné à un millésime publié
 * (sinon aucune page n'existe) ; une commune dont le département n'a pas de slug au
 * référentiel figé (DOM) est écartée, faute d'URL constructible.
 */
final readonly class CommunesIndexables
{
    private const SELECT =
        'SELECT c.code_insee, c.nom, c.code_departement, cm.mode, cm.rayon_retenu_km, cm.poids_pooling
         FROM data.coefficient_maille cm
         JOIN data.commune c ON c.code_insee = cm.code_insee
         ORDER BY c.code_insee';

    public function __construct(
        private LectureModelePublie $lecture,
        private Connection $connexion,
        private RepertoireDepartements $departements,
    ) {
    }

    /**
     * @return list<array{departement: string, commune: string}>
     */
    public function slugs(): array
    {
        if (null === $this->lecture->publication()) {
            return [];
        }

        $slugs = [];
        foreach ($this->connexion->fetchAllAssociative(self::SELECT) as $ligne) {
            if (!self::estIndexable($ligne)) {
                continue;
            }

            $codeDepartement = \is_string($ligne['code_departement']) ? trim($ligne['code_departement']) : '';
            $departementSlug = $this->departements->slug($codeDepartement);
            if (null === $departementSlug) {
                continue;
            }

            $nom = \is_string($ligne['nom']) ? $ligne['nom'] : '';
            $slugs[] = ['departement' => $departementSlug, 'commune' => Slug::depuis($nom)->valeur()];
        }

        return $slugs;
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function estIndexable(array $ligne): bool
    {
        $rayon = is_numeric($ligne['rayon_retenu_km']) ? (int) $ligne['rayon_retenu_km'] : 0;

        return IndexabiliteCommune::depuis(
            PorteeEstimation::depuis(
                \is_string($ligne['mode']) ? $ligne['mode'] : '',
                0 === $rayon ? null : $rayon,
            ),
            is_numeric($ligne['poids_pooling']) ? (float) $ligne['poids_pooling'] : null,
        )->estIndexable();
    }
}
