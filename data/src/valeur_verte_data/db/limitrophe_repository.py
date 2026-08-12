"""Contiguïté communale : stockage des contours et dérivation ``ST_Touches`` (#121).

Deux communes sont limitrophes si leurs contours **partagent une frontière**.
La géométrie et le prédicat topologique vivent en base (PostGIS) ; ce module ne
porte que l'accès. La relation est calculée sur **tous les contours chargés**,
donc elle franchit les frontières départementales dès que le voisin est présent
(règle des limitrophes, §3.2 ; #24/#91).
"""

from __future__ import annotations

from .runner import Connection

_UPSERT_CONTOUR = (
    "INSERT INTO data.commune_contour (code_insee, code_departement, geom) "
    "VALUES (%s, %s, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326))) "
    "ON CONFLICT (code_insee) DO UPDATE SET "
    "code_departement = EXCLUDED.code_departement, geom = EXCLUDED.geom"
)

# Paires limitrophes : jointure du contour sur lui-même, une seule orientation
# (a < b), interiors disjoints mais frontières en contact (ST_Touches).
_DERIVE = (
    "INSERT INTO data.commune_limitrophe (code_insee_a, code_insee_b) "
    "SELECT a.code_insee, b.code_insee "
    "FROM data.commune_contour a "
    "JOIN data.commune_contour b ON a.code_insee < b.code_insee "
    "AND ST_Touches(a.geom, b.geom) "
    "ON CONFLICT DO NOTHING RETURNING 1"
)

# Voisins de chaque commune du département, dans les deux sens et quel que soit
# le département du voisin (le franchissement de frontière est justement l'objet).
_READ_ADJACENCE = (
    "SELECT code_insee_a, code_insee_b FROM data.commune_limitrophe "
    "WHERE substr(code_insee_a, 1, 2) = %s "
    "UNION ALL "
    "SELECT code_insee_b, code_insee_a FROM data.commune_limitrophe "
    "WHERE substr(code_insee_b, 1, 2) = %s"
)


def upsert_contour(conn: Connection, code_insee: str, code_departement: str, contour: str) -> None:
    """Enregistre (ou remplace) le contour GeoJSON d'une commune."""
    conn.execute(_UPSERT_CONTOUR, [code_insee, code_departement, contour])


def derive_limitrophes(conn: Connection) -> int:
    """Dérive les paires limitrophes des contours chargés ; retourne le nb ajouté.

    Idempotent : une paire déjà connue n'est pas réinsérée (``ON CONFLICT``).
    """
    return len(conn.execute(_DERIVE).fetchall())


def read_adjacence(conn: Connection, departement: str) -> dict[str, tuple[str, ...]]:
    """Voisines de chaque commune du département, voisins inter-départements compris."""
    rows = conn.execute(_READ_ADJACENCE, [departement, departement]).fetchall()
    adjacence: dict[str, list[str]] = {}
    for commune, voisin in rows:
        adjacence.setdefault(str(commune).strip(), []).append(str(voisin).strip())
    return {commune: tuple(sorted(voisins)) for commune, voisins in adjacence.items()}
