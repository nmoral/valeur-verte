<?php

declare(strict_types=1);

namespace App\Publication;

use Doctrine\DBAL\Connection;

/**
 * Répertoire des communes lu dans le schéma « data » en lecture seule (décision
 * D6), pour résoudre une URL SEO ``/valeur-verte/{departement}/{commune}`` vers sa
 * maille (#359, SEO Vague 2 #338).
 *
 * Le slug départemental de l'URL est traduit en code via {@see RepertoireDepartements}
 * (contrat d'URL figé), puis le slug de commune est cherché **parmi les seules
 * communes de ce département** : la résolution est ainsi bornée au département de
 * l'URL, et les homonymes nationaux (une « Sainte-Colombe » par département) ne se
 * télescopent pas. Le slug n'est pas stocké : il est recalculé par {@see Slug} sur
 * le nom, source unique de la règle.
 */
final readonly class RepertoireCommunes
{
    public function __construct(
        private Connection $connexion,
        private RepertoireDepartements $departements,
    ) {
    }

    public function resoudre(string $slugDepartement, string $slugCommune): ?Commune
    {
        $codeDepartement = $this->departements->code($slugDepartement);
        if (null === $codeDepartement) {
            return null;
        }

        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT c.code_insee, c.nom, c.code_departement, e.code_epci
             FROM data.commune c
             LEFT JOIN data.commune_epci e ON e.code_insee = c.code_insee
             WHERE c.code_departement = :departement
             ORDER BY c.code_insee',
            ['departement' => $codeDepartement],
        );

        foreach ($lignes as $ligne) {
            $commune = self::depuisLigne($ligne);
            // ORDER BY code_insee : sur un éventuel homonyme intra-département, la
            // maille au plus petit code l'emporte — désambiguïsation déterministe.
            if ($commune->slug() === $slugCommune) {
                return $commune;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function depuisLigne(array $ligne): Commune
    {
        return new Commune(
            codeInsee: \is_string($ligne['code_insee']) ? trim($ligne['code_insee']) : '',
            nom: \is_string($ligne['nom']) ? $ligne['nom'] : '',
            codeDepartement: \is_string($ligne['code_departement']) ? trim($ligne['code_departement']) : '',
            codeEpci: \is_string($ligne['code_epci']) ? trim($ligne['code_epci']) : null,
        );
    }
}
