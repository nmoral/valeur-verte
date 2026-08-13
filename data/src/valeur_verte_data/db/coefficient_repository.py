"""Écriture des coefficients pré-calculés dans ``data.coefficient_maille`` (§10.6).

Une ligne par commune (clé ``code_insee``) : upsert idempotent. ``coef_dpe``,
``ic_dpe``, ``bandes`` et ``effectifs_dpe`` sont sérialisés en JSONB. Le rayon
départemental (``None``) est stocké à ``0`` (la colonne est ``NOT NULL``). Le cran
de la cascade (``mode``) et le tier de fiabilité (``palier``) sont stockés par leur
valeur d'énumération (#169) — le contrat data→app en a besoin pour la restitution.
"""

from __future__ import annotations

import json

from ..batch import MailleCoefficient
from .runner import Connection

_UPSERT = (
    "INSERT INTO data.coefficient_maille ("
    "code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane, "
    "coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte, "
    "mode, palier, bandes, effectifs_dpe"
    ") VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb, %s::jsonb, %s, "
    "%s, %s, %s::jsonb, %s::jsonb) "
    "ON CONFLICT (code_insee) DO UPDATE SET "
    "millesime = EXCLUDED.millesime, rayon_retenu_km = EXCLUDED.rayon_retenu_km, "
    "n_transactions = EXCLUDED.n_transactions, r2 = EXCLUDED.r2, "
    "erreur_mediane = EXCLUDED.erreur_mediane, coef_m2_bati = EXCLUDED.coef_m2_bati, "
    "coef_m2_terrain = EXCLUDED.coef_m2_terrain, coef_dpe = EXCLUDED.coef_dpe, "
    "ic_dpe = EXCLUDED.ic_dpe, alerte = EXCLUDED.alerte, mode = EXCLUDED.mode, "
    "palier = EXCLUDED.palier, bandes = EXCLUDED.bandes, "
    "effectifs_dpe = EXCLUDED.effectifs_dpe, calcule_le = now()"
)


_DELETE = "DELETE FROM data.coefficient_maille WHERE code_insee = %s"

# Historique versionné (REG-1, #129) : mêmes colonnes et mêmes valeurs que la table
# vive, mais conflit sur (code_insee, millesime) — chaque millésime est conservé.
_ARCHIVE = (
    "INSERT INTO data.coefficient_maille_historique ("
    "code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane, "
    "coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte, "
    "mode, palier, bandes, effectifs_dpe"
    ") VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb, %s::jsonb, %s, "
    "%s, %s, %s::jsonb, %s::jsonb) "
    "ON CONFLICT (code_insee, millesime) DO UPDATE SET "
    "rayon_retenu_km = EXCLUDED.rayon_retenu_km, n_transactions = EXCLUDED.n_transactions, "
    "r2 = EXCLUDED.r2, erreur_mediane = EXCLUDED.erreur_mediane, "
    "coef_m2_bati = EXCLUDED.coef_m2_bati, coef_m2_terrain = EXCLUDED.coef_m2_terrain, "
    "coef_dpe = EXCLUDED.coef_dpe, ic_dpe = EXCLUDED.ic_dpe, alerte = EXCLUDED.alerte, "
    "mode = EXCLUDED.mode, palier = EXCLUDED.palier, bandes = EXCLUDED.bandes, "
    "effectifs_dpe = EXCLUDED.effectifs_dpe, calcule_le = now()"
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


def delete_coefficient(conn: Connection, code_insee: str) -> None:
    """Efface le coefficient d'une commune devenue non calculable.

    Sans cela, un recalcul qui refuse une maille laisserait l'ancienne valeur
    en base : elle continuerait d'être servie alors qu'elle vient d'être jugée
    non publiable.
    """
    conn.execute(_DELETE, [code_insee])


def upsert_coefficient(conn: Connection, maille: MailleCoefficient) -> None:
    """Insère ou met à jour la ligne vive ``coefficient_maille`` d'une commune."""
    conn.execute(_UPSERT, _params(maille))


def archiver_coefficient(conn: Connection, maille: MailleCoefficient) -> None:
    """Archive la maille dans l'historique versionné par millésime (REG-1, #129).

    Distinct de l'écriture vive : conserve chaque millésime pour comparer au
    précédent. Idempotent au recalcul d'un même millésime (conflit sur la clé).
    """
    conn.execute(_ARCHIVE, _params(maille))
