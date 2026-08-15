"""Archivage des coefficients pré-calculés, versionné par millésime (§10.6).

Le batch écrit **uniquement** l'historique (``data.coefficient_maille_historique``,
clé ``code_insee`` + ``millesime``). La table vive ``data.coefficient_maille`` n'est
alimentée qu'à la publication d'un millésime (REG-2, #130), par les fonctions SQL de
{@see publication_repository} — d'où l'absence d'écriture vive ici.

``coef_dpe``, ``ic_dpe``, ``bandes`` et ``effectifs_dpe`` sont sérialisés en JSONB.
Le rayon départemental (``None``) est stocké à ``0`` (la colonne est ``NOT NULL``).
Le cran de la cascade (``mode``) et le tier de fiabilité (``palier``) sont stockés
par leur valeur d'énumération (#169) — le contrat data→app en a besoin pour la
restitution.
"""

from __future__ import annotations

import json
from typing import Any

from ..batch import MailleCoefficient
from ..degraded import CoverageMode, PalierFiabilite
from ..ecarts import ClasseEcart, MotifRefus
from .runner import Connection

# Historique versionné (REG-1, #129) : mêmes colonnes et mêmes valeurs que la table
# vive, mais conflit sur (code_insee, millesime) — chaque millésime est conservé.
_ARCHIVE = (
    "INSERT INTO data.coefficient_maille_historique ("
    "code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane, "
    "coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte, "
    "mode, palier, bandes, effectifs_dpe, classe_ecart, ecart, ecart_coefficient"
    ") VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb, %s::jsonb, %s, "
    "%s, %s, %s::jsonb, %s::jsonb, %s, %s, %s) "
    "ON CONFLICT (code_insee, millesime) DO UPDATE SET "
    "rayon_retenu_km = EXCLUDED.rayon_retenu_km, n_transactions = EXCLUDED.n_transactions, "
    "r2 = EXCLUDED.r2, erreur_mediane = EXCLUDED.erreur_mediane, "
    "coef_m2_bati = EXCLUDED.coef_m2_bati, coef_m2_terrain = EXCLUDED.coef_m2_terrain, "
    "coef_dpe = EXCLUDED.coef_dpe, ic_dpe = EXCLUDED.ic_dpe, alerte = EXCLUDED.alerte, "
    "mode = EXCLUDED.mode, palier = EXCLUDED.palier, bandes = EXCLUDED.bandes, "
    "effectifs_dpe = EXCLUDED.effectifs_dpe, classe_ecart = EXCLUDED.classe_ecart, "
    "ecart = EXCLUDED.ecart, ecart_coefficient = EXCLUDED.ecart_coefficient, "
    "calcule_le = now()"
)


# REG-2 (#130) : le refus est **différé** — il ne retire la commune du service qu'à
# la publication du millésime, jamais au moment du calcul. Sans cela, un millésime
# calculé puis abandonné aurait déjà fait disparaître des communes.
_REFUS = (
    "INSERT INTO data.maille_refusee (code_insee, millesime, motif) "
    "VALUES (%s, %s, %s) "
    "ON CONFLICT (code_insee, millesime) DO UPDATE SET motif = EXCLUDED.motif"
)


# REG-1 (#129) : le « précédent » d'un millésime = la ligne d'historique de plus
# grand millésime strictement inférieur. Colonnes dans l'ordre lu par ``_depuis_row``.
_SELECT_PRECEDENT = (
    "SELECT code_insee, millesime, mode, rayon_retenu_km, n_transactions, r2, "
    "erreur_mediane, coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte, "
    "palier, bandes, effectifs_dpe "
    "FROM data.coefficient_maille_historique "
    "WHERE code_insee = %s AND millesime < %s "
    "ORDER BY millesime DESC LIMIT 1"
)


def _params(maille: MailleCoefficient) -> list[object]:
    """Valeurs positionnelles d'une maille, partagées par l'écriture vive et l'archive.

    Le rayon départemental (``None``) est stocké à ``0`` (colonne ``NOT NULL``) ; le
    cran et le palier par leur valeur d'énumération ; les dicts en JSONB sérialisé.
    """
    return [
        maille.code_insee,
        maille.millesime,
        maille.rayon_retenu_km if maille.rayon_retenu_km is not None else 0,
        maille.n_transactions,
        maille.r2,
        maille.erreur_mediane,
        maille.coef_m2_bati,
        maille.coef_m2_terrain,
        json.dumps(maille.coef_dpe),
        json.dumps(maille.ic_dpe),
        maille.alerte,
        maille.mode.value,
        maille.palier.value,
        json.dumps(maille.bandes),
        json.dumps(maille.effectifs_dpe),
    ]


def archiver_coefficient(
    conn: Connection,
    maille: MailleCoefficient,
    classe_ecart: ClasseEcart | None = None,
    ecart: float | None = None,
    ecart_coefficient: str | None = None,
) -> None:
    """Archive la maille dans l'historique versionné par millésime (REG-1, #129).

    Distinct de l'écriture vive : conserve chaque millésime pour comparer au
    précédent. Idempotent au recalcul d'un même millésime (conflit sur la clé).

    Le verdict REG-1 est porté ici (REG-2, #130) : c'est lui qui permet à la
    publication d'écarter les mailles aberrantes. Sans précédent à comparer, la
    classe est ``None`` — absence de verdict, pas verdict favorable.
    """
    conn.execute(
        _ARCHIVE,
        [
            *_params(maille),
            None if classe_ecart is None else classe_ecart.value,
            ecart,
            ecart_coefficient,
        ],
    )


def enregistrer_refus(conn: Connection, code_insee: str, millesime: int, motif: MotifRefus) -> None:
    """Note qu'une commune n'est pas calculable sous ce millésime (REG-2, #130).

    Le refus ne prend effet qu'à la publication : c'est elle qui retirera la commune
    du service. Différer évite qu'un millésime abandonné laisse des trous.
    """
    conn.execute(_REFUS, [code_insee, millesime, motif.value])


def lire_coefficient_precedent(
    conn: Connection, code_insee: str, millesime: int
) -> MailleCoefficient | None:
    """Relit le millésime **antérieur le plus récent** d'une commune (REG-1, #129).

    Source du « précédent » du rapport d'écarts : la ligne d'historique de plus grand
    millésime strictement inférieur à ``millesime``. Reconstruit une
    ``MailleCoefficient`` fidèle (JSONB → dict via psycopg, rayon départemental 0 →
    ``None``, mode/palier depuis leur valeur d'énumération). ``None`` si la commune
    n'a pas de millésime antérieur (première publication).
    """
    rows = conn.execute(_SELECT_PRECEDENT, [code_insee, millesime]).fetchall()
    return _depuis_row(rows[0]) if rows else None


def _depuis_row(row: tuple[Any, ...]) -> MailleCoefficient:
    """Reconstruit une maille depuis une ligne d'historique (ordre de ``_SELECT_PRECEDENT``)."""
    ic_brut = row[10] or {}
    return MailleCoefficient(
        code_insee=str(row[0]).strip(),
        millesime=int(row[1]),
        mode=CoverageMode(row[2]),
        rayon_retenu_km=None if not row[3] else int(row[3]),
        n_transactions=int(row[4]),
        r2=float(row[5]),
        erreur_mediane=None if row[6] is None else float(row[6]),
        coef_m2_bati=float(row[7]),
        coef_m2_terrain=float(row[8]),
        coef_dpe={classe: float(coef) for classe, coef in row[9].items()},
        ic_dpe={classe: (float(bas), float(haut)) for classe, (bas, haut) in ic_brut.items()},
        alerte=None if row[11] is None else str(row[11]),
        palier=PalierFiabilite(row[12]),
        bandes={classe: str(rep) for classe, rep in (row[13] or {}).items()},
        effectifs_dpe={classe: int(n) for classe, n in (row[14] or {}).items()},
    )
