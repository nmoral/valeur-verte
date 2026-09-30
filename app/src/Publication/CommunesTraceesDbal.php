<?php

declare(strict_types=1);

namespace App\Publication;

use Doctrine\DBAL\Connection;

/**
 * Lit les contours communaux d'un département (#392) pour la choroplèthe du hub :
 * une jointure ``commune_contour`` ⋈ ``commune`` ⋈ ``coefficient_maille`` dans le
 * schéma « data » (lecture seule, décision D6).
 *
 * La géométrie PostGIS est servie en GeoJSON, allégée par ``ST_SimplifyPreserveTopology``
 * pour ne pas transporter des milliers de points par commune (la page est en cache
 * proxy, mais la charge utile reste dans le HTML). Les fonctions spatiales sont
 * qualifiées ``public.`` : le rôle « app » a ``search_path=app`` et ne les verrait
 * pas autrement.
 */
final readonly class CommunesTraceesDbal
{
    /** Tolérance de simplification en degrés (~40 m) : détail suffisant à l'échelle départementale. */
    private const TOLERANCE_DEG = 0.0004;

    private const SELECT =
        'SELECT cc.code_insee,
                c.nom,
                cm.mode,
                cm.coef_dpe,
                public.ST_AsGeoJSON(public.ST_SimplifyPreserveTopology(cc.geom, :tol)) AS geojson
         FROM data.commune_contour cc
         LEFT JOIN data.commune c            ON c.code_insee = cc.code_insee
         LEFT JOIN data.coefficient_maille cm ON cm.code_insee = cc.code_insee
         WHERE cc.code_departement = :dept
         ORDER BY cc.code_insee';

    public function __construct(private Connection $connexion)
    {
    }

    /**
     * @return list<CommuneTracee> ordonnées par code INSEE
     */
    public function pour(string $codeDepartement): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            self::SELECT,
            ['dept' => $codeDepartement, 'tol' => self::TOLERANCE_DEG],
        );

        $traces = [];
        foreach ($lignes as $ligne) {
            $code = \is_string($ligne['code_insee']) ? trim($ligne['code_insee']) : '';
            $nom = \is_string($ligne['nom']) ? $ligne['nom'] : $code;
            $traces[] = new CommuneTracee(
                $code,
                $nom,
                $this->anneaux(\is_string($ligne['geojson']) ? $ligne['geojson'] : ''),
                $this->maille($code, $ligne['mode'] ?? null, $ligne['coef_dpe'] ?? null),
            );
        }

        return $traces;
    }

    /**
     * Aplati une géométrie GeoJSON (Polygon ou MultiPolygon) en une liste d'anneaux
     * lon/lat, tels que {@see ProjectionDepartement} les attend.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function anneaux(string $geojson): array
    {
        $geometrie = json_decode($geojson, true);
        if (!\is_array($geometrie) || !isset($geometrie['type'], $geometrie['coordinates']) || !\is_array($geometrie['coordinates'])) {
            return [];
        }

        // Un Polygon est une liste d'anneaux ; un MultiPolygon une liste de polygones.
        $polygones = 'MultiPolygon' === $geometrie['type'] ? $geometrie['coordinates'] : [$geometrie['coordinates']];

        $anneaux = [];
        foreach ($polygones as $polygone) {
            if (!\is_array($polygone)) {
                continue;
            }
            foreach ($polygone as $anneau) {
                if (!\is_array($anneau)) {
                    continue;
                }
                $sommets = [];
                foreach ($anneau as $sommet) {
                    if (\is_array($sommet) && isset($sommet[0], $sommet[1]) && is_numeric($sommet[0]) && is_numeric($sommet[1])) {
                        $sommets[] = [(float) $sommet[0], (float) $sommet[1]];
                    }
                }
                if ([] !== $sommets) {
                    $anneaux[] = $sommets;
                }
            }
        }

        return $anneaux;
    }

    private function maille(string $code, mixed $mode, mixed $coefDpe): ?Maille
    {
        if (!\is_string($mode) || !\is_string($coefDpe)) {
            return null;
        }

        $coefficients = json_decode($coefDpe, true);
        if (!\is_array($coefficients)) {
            return null;
        }

        $effets = [];
        foreach ($coefficients as $classe => $effet) {
            if (\is_string($classe) && is_numeric($effet)) {
                $effets[$classe] = (float) $effet;
            }
        }

        return new Maille($code, $mode, $effets);
    }
}
