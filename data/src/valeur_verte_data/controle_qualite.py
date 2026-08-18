"""Contrôle de validité de ``data.vente`` — cœur pur (audit read-only).

L'audit **constate** des anomalies sur les ventes déjà ingérées ; il ne filtre ni
ne corrige (la base brute S12 est volontaire, cf. ``docs/MOTEUR-REGLES-CALCUL.md``
§3). Distinct des portes DAT (schéma #124, volumétrie #125, appariement #126) qui
barrent *à l'ingestion*, et des invariants/REG-1 côté calcul : ici on scanne la
base telle qu'elle est et on remonte ce qui la pollue.

Deux sévérités :

- ``ANOMALIE`` — donnée incohérente qui casse le modèle ou trahit un bug
  d'ingestion (prix ≤ 0, doublon de mutation, geom sans coordonnées). Fait
  **échouer** le verdict (code de sortie ≠ 0).
- ``ALERTE`` — valeur suspecte mais possiblement légitime (bâti gigantesque,
  coordonnées hors métropole, DPE hors échelle). Rapportée, **non bloquante**.

Le module est **pur** (aucune connexion) : la couche DB
(``db.controle_repository``) n'est qu'un pourvoyeur de ``LigneVente``. Les
doublons, intrinsèquement cross-lignes, ont leur propre fonction ; le reste est
un prédicat par ligne.
"""

from __future__ import annotations

import csv
from collections.abc import Callable, Collection, Iterable
from dataclasses import dataclass, field
from enum import Enum
from typing import TextIO

# Échelle DPE valide (§9). Une étiquette hors de cet ensemble est suspecte.
_ECHELLE_DPE = "ABCDEFG"


class Severite(Enum):
    """Gravité d'une anomalie ; l'ordre départage le tri du rapport (INV-5)."""

    ANOMALIE = "anomalie"
    ALERTE = "alerte"


# ANOMALIE avant ALERTE dans le rapport : le bloquant se lit en premier.
_ORDRE_SEVERITE = {Severite.ANOMALIE: 0, Severite.ALERTE: 1}

# Collecteur d'anomalies passé aux contrôles privés (code, sévérité, message).
_Signaler = Callable[[str, Severite, str], None]


@dataclass(frozen=True)
class Anomalie:
    """Un constat d'audit : un code stable, sa gravité, un message et sa cible."""

    code: str
    severite: Severite
    message: str
    reference: str


@dataclass(frozen=True)
class LigneVente:
    """Projection d'une ligne ``data.vente`` utile aux contrôles.

    ``a_geom`` reflète ``geom IS NOT NULL`` en base : le décorréler des
    coordonnées permet de détecter un point non matérialisé (ou l'inverse).
    """

    id: int
    id_mutation: str
    millesime: int
    code_insee: str
    date_mutation: str | None
    prix: float | None
    surface_bati: float | None
    surface_terrain: float | None
    prix_m2: float | None
    etiquette_dpe: str | None
    longitude: float | None
    latitude: float | None
    a_geom: bool


@dataclass(frozen=True)
class ControleConfig:
    """Seuils de vraisemblance de l'audit — dédiés (l'audit n'est pas le moteur).

    La base étant brute (S12), ces plafonds ne **filtrent** rien : ils ne servent
    qu'à *signaler* des valeurs improbables. Bbox = métropole (Corse comprise).
    """

    prix_m2_tolerance_pct: float = 5.0
    bati_max_absurde: float = 2_000.0
    terrain_max_absurde: float = 1_000_000.0  # 100 ha
    bbox_lon_min: float = -5.5
    bbox_lon_max: float = 9.8
    bbox_lat_min: float = 41.0
    bbox_lat_max: float = 51.5


DEFAULT_CONFIG = ControleConfig()


def controler_ligne(
    ligne: LigneVente,
    *,
    communes_connues: Collection[str] | None = None,
    departements_references: Collection[str] | None = None,
    config: ControleConfig = DEFAULT_CONFIG,
) -> tuple[Anomalie, ...]:
    """Toutes les anomalies portées par une ligne, dans un ordre stable.

    L'orphelinage n'est jugé que dans un **département couvert** par le référentiel :
    ``communes_connues`` **et** ``departements_references`` doivent être fournis, et
    le département de la vente doit y figurer. Sinon le contrôle reste muet — un
    département absent du référentiel n'est pas chargé, pas peuplé d'orphelins.
    """
    reference = f"vente#{ligne.id}"
    anomalies: list[Anomalie] = []

    def signaler(code: str, severite: Severite, message: str) -> None:
        anomalies.append(Anomalie(code, severite, message, reference))

    _controler_montants(ligne, config, signaler)
    _controler_tailles(ligne, config, signaler)
    _controler_geo(ligne, config, signaler)
    _controler_referentiel(ligne, communes_connues, departements_references, signaler)

    return tuple(anomalies)


def departements_de(communes: Collection[str]) -> frozenset[str]:
    """Départements représentés dans un référentiel de communes (préfixe INSEE).

    Le préfixe à deux caractères suffit et reste **cohérent** avec la dérivation du
    département d'une vente (``code_insee[:2]``), Corse comprise (``2A``/``2B``) : le
    gating d'orphelinage compare des grandeurs dérivées de la même façon.
    """
    return frozenset(code[:2] for code in communes)


def detecter_doublons(lignes: Iterable[LigneVente]) -> tuple[Anomalie, ...]:
    """Anomalies de doublon sur la clé ``(id_mutation, millesime)``, en trois classes :

    - ``mutation_multi_communes`` (ALERTE) — **autant de communes distinctes que de
      lignes** : une vente DVF qui chevauche plusieurs communes est agrégée en une
      ligne par commune (chacune porte sa part — surfaces, DPE propres). Structure
      normale, signalée sans faire échouer. C'est le critère par **nombre de
      communes**, pas par égalité des valeurs : les parts diffèrent légitimement ;
    - ``doublon`` (ANOMALIE) — une commune est **répétée** avec des copies
      strictement identiques : ré-ingestion sans purge, vraie pollution ;
    - ``doublon_divergent`` (ANOMALIE) — une commune est répétée avec des valeurs
      métier **incohérentes** : incohérence réelle, la plus grave.

    Sortie triée sur la référence (INV-5).
    """
    groupes: dict[tuple[str, int], list[LigneVente]] = {}
    for ligne in lignes:
        groupes.setdefault((ligne.id_mutation, ligne.millesime), []).append(ligne)

    anomalies: list[Anomalie] = []
    for (id_mutation, millesime), groupe in groupes.items():
        if len(groupe) < 2:
            continue
        reference = f"mutation {id_mutation}/{millesime}"
        occurrences = len(groupe)
        communes_distinctes = len({v.code_insee for v in groupe})
        if communes_distinctes == occurrences:
            anomalies.append(
                Anomalie(
                    "mutation_multi_communes",
                    Severite.ALERTE,
                    f"mutation répartie sur {communes_distinctes} communes",
                    reference,
                )
            )
        elif _valeurs_metier_identiques(groupe):
            anomalies.append(
                Anomalie(
                    "doublon",
                    Severite.ANOMALIE,
                    f"doublon strict ({occurrences} occurrences)",
                    reference,
                )
            )
        else:
            anomalies.append(
                Anomalie(
                    "doublon_divergent",
                    Severite.ANOMALIE,
                    f"doublon aux valeurs divergentes ({occurrences} occurrences)",
                    reference,
                )
            )

    return tuple(sorted(anomalies, key=lambda a: a.reference))


@dataclass(frozen=True)
class RapportControle:
    """Synthèse d'un audit : anomalies triées, volume contrôlé, verdict."""

    anomalies: tuple[Anomalie, ...]
    lignes_controlees: int = field(default=0)

    @property
    def n_anomalies(self) -> int:
        return sum(1 for a in self.anomalies if a.severite is Severite.ANOMALIE)

    @property
    def n_alertes(self) -> int:
        return sum(1 for a in self.anomalies if a.severite is Severite.ALERTE)

    @property
    def valide(self) -> bool:
        """Vrai si aucune ANOMALIE — les ALERTES sont signalées sans faire échouer."""
        return self.n_anomalies == 0

    def resume(self) -> str:
        return (
            f"{self.lignes_controlees} ligne(s) contrôlée(s) : "
            f"{self.n_anomalies} anomalie(s), {self.n_alertes} alerte(s)"
        )


def construire_rapport(anomalies: Iterable[Anomalie], *, lignes_controlees: int) -> RapportControle:
    """Assemble un rapport : anomalies triées (ANOMALIE avant ALERTE), volume."""
    triees = tuple(
        sorted(anomalies, key=lambda a: (_ORDRE_SEVERITE[a.severite], a.reference, a.code))
    )
    return RapportControle(anomalies=triees, lignes_controlees=lignes_controlees)


def ecrire_controle_csv(rapport: RapportControle, fichier: TextIO) -> None:
    """Écrit le détail des anomalies en CSV (une ligne par anomalie, en-tête inclus)."""
    writer = csv.writer(fichier)
    writer.writerow(["severite", "code", "reference", "message"])
    for anomalie in rapport.anomalies:
        writer.writerow(
            [anomalie.severite.value, anomalie.code, anomalie.reference, anomalie.message]
        )


def _controler_montants(ligne: LigneVente, config: ControleConfig, signaler: _Signaler) -> None:
    if ligne.prix is None or ligne.prix <= 0:
        signaler("prix_non_positif", Severite.ANOMALIE, f"prix {ligne.prix}")
    if ligne.surface_bati is None or ligne.surface_bati <= 0:
        signaler("bati_non_positif", Severite.ANOMALIE, f"surface_bati {ligne.surface_bati}")

    if (
        ligne.prix_m2 is not None
        and ligne.prix is not None
        and ligne.prix > 0
        and ligne.surface_bati is not None
        and ligne.surface_bati > 0
    ):
        attendu = ligne.prix / ligne.surface_bati
        ecart_pct = abs(ligne.prix_m2 - attendu) / attendu * 100
        if ecart_pct > config.prix_m2_tolerance_pct:
            signaler(
                "prix_m2_incoherent",
                Severite.ALERTE,
                f"prix_m2 {ligne.prix_m2} vs {attendu:.0f} attendu ({ecart_pct:.0f} %)",
            )


def _controler_tailles(ligne: LigneVente, config: ControleConfig, signaler: _Signaler) -> None:
    if ligne.surface_terrain is not None and ligne.surface_terrain < 0:
        signaler("terrain_negatif", Severite.ANOMALIE, f"surface_terrain {ligne.surface_terrain}")
    if ligne.surface_bati is not None and ligne.surface_bati > config.bati_max_absurde:
        signaler("bati_absurde", Severite.ALERTE, f"surface_bati {ligne.surface_bati} m²")
    if ligne.surface_terrain is not None and ligne.surface_terrain > config.terrain_max_absurde:
        signaler("terrain_absurde", Severite.ALERTE, f"surface_terrain {ligne.surface_terrain} m²")


def _controler_geo(ligne: LigneVente, config: ControleConfig, signaler: _Signaler) -> None:
    a_coordonnees = ligne.longitude is not None and ligne.latitude is not None
    if a_coordonnees and not ligne.a_geom:
        signaler("geom_manquant", Severite.ANOMALIE, "coordonnées présentes mais geom NULL")
    elif ligne.a_geom and not a_coordonnees:
        signaler("coordonnees_manquantes", Severite.ANOMALIE, "geom présent mais coordonnées NULL")

    if (
        ligne.longitude is not None
        and ligne.latitude is not None
        and not (
            config.bbox_lon_min <= ligne.longitude <= config.bbox_lon_max
            and config.bbox_lat_min <= ligne.latitude <= config.bbox_lat_max
        )
    ):
        signaler(
            "hors_bbox",
            Severite.ALERTE,
            f"coordonnées ({ligne.longitude}, {ligne.latitude}) hors métropole",
        )


def _controler_referentiel(
    ligne: LigneVente,
    communes_connues: Collection[str] | None,
    departements_references: Collection[str] | None,
    signaler: _Signaler,
) -> None:
    if ligne.etiquette_dpe is not None and ligne.etiquette_dpe not in _ECHELLE_DPE:
        signaler("dpe_invalide", Severite.ALERTE, f"étiquette DPE {ligne.etiquette_dpe!r}")
    if (
        communes_connues is not None
        and departements_references is not None
        and ligne.code_insee[:2] in departements_references
        and ligne.code_insee not in communes_connues
    ):
        signaler(
            "commune_orpheline", Severite.ALERTE, f"code INSEE {ligne.code_insee} hors référentiel"
        )
    if _annee(ligne.date_mutation) not in (None, ligne.millesime):
        signaler(
            "millesime_incoherent",
            Severite.ALERTE,
            f"date {ligne.date_mutation} ≠ millésime {ligne.millesime}",
        )


def _valeurs_metier_identiques(groupe: list[LigneVente]) -> bool:
    """Vrai si toutes les copies portent les mêmes valeurs métier (doublon strict)."""
    empreintes = {
        (v.code_insee, v.prix, v.surface_bati, v.surface_terrain, v.date_mutation, v.etiquette_dpe)
        for v in groupe
    }
    return len(empreintes) == 1


def _annee(date_mutation: str | None) -> int | None:
    """Année ISO d'une date ``YYYY-...``, ou ``None`` si absente/illisible."""
    if date_mutation is None:
        return None
    tete = date_mutation[:4]
    return int(tete) if tete.isdigit() else None
