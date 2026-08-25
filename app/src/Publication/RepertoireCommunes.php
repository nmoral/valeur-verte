<?php

declare(strict_types=1);

namespace App\Publication;

use Doctrine\DBAL\Connection;

/**
 * Répertoire des communes lu dans le schéma « data » en lecture seule (décision
 * D6), pour les pages SEO de la Vague 2 (#338, #359) : il résout une URL
 * ``/valeur-verte/{departement}/{commune}`` vers sa maille, retrouve une commune
 * par son code (sens inverse, pour reconstruire une URL) et énumère les communes
 * d'un département ou d'un EPCI (maillage descendant, sitemap).
 *
 * Le slug départemental de l'URL est traduit en code via {@see RepertoireDepartements}
 * (contrat d'URL figé), puis le slug de commune est cherché **parmi les seules
 * communes de ce département** : la résolution est ainsi bornée au département de
 * l'URL, et les homonymes nationaux (une « Sainte-Colombe » par département) ne se
 * télescopent pas. Le slug n'est jamais stocké : il est recalculé par {@see Slug}
 * sur le nom, source unique de la règle.
 */
final readonly class RepertoireCommunes
{
    /**
     * Projection commune : identité, nom, département et rattachement EPCI (le
     * ``LEFT JOIN`` laisse ``code_epci`` à ``null`` si le rattachement manque).
     */
    private const SELECT_BASE =
        'SELECT c.code_insee, c.nom, c.code_departement, e.code_epci
         FROM data.commune c
         LEFT JOIN data.commune_epci e ON e.code_insee = c.code_insee';

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

        foreach ($this->communesDe($codeDepartement) as $commune) {
            // Ordre par code_insee (voir communesDe) : sur un éventuel homonyme
            // intra-département, la maille au plus petit code l'emporte —
            // désambiguïsation déterministe.
            if ($commune->slug() === $slugCommune) {
                return $commune;
            }
        }

        return null;
    }

    /**
     * Sens inverse : la commune d'un ``code_insee``, ou ``null`` si elle n'est pas
     * au référentiel. Sert à reconstruire l'URL d'une commune (sitemap, maillage).
     */
    public function pour(string $codeInsee): ?Commune
    {
        $ligne = $this->connexion->fetchAssociative(
            self::SELECT_BASE.' WHERE c.code_insee = :code',
            ['code' => $codeInsee],
        );

        return false === $ligne ? null : self::depuisLigne($ligne);
    }

    /**
     * Les communes d'un département, ordonnées par ``code_insee`` (stable, sans
     * dépendance à la collation ; la présentation trie par nom si besoin).
     *
     * @return list<Commune>
     */
    public function communesDe(string $codeDepartement): array
    {
        return $this->enumerer('WHERE c.code_departement = :cle', $codeDepartement);
    }

    /**
     * Les communes rattachées à un EPCI (SIREN), même ordre déterministe.
     *
     * @return list<Commune>
     */
    public function communesDeLEpci(string $codeEpci): array
    {
        return $this->enumerer('WHERE e.code_epci = :cle', $codeEpci);
    }

    /**
     * @return list<Commune>
     */
    private function enumerer(string $condition, string $cle): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            self::SELECT_BASE.' '.$condition.' ORDER BY c.code_insee',
            ['cle' => $cle],
        );

        $communes = [];
        foreach ($lignes as $ligne) {
            $communes[] = self::depuisLigne($ligne);
        }

        return $communes;
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
