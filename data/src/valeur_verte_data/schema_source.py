"""DAT-1 (#124) — contrat de schéma des sources ingérées.

Le pipeline lit des fichiers publics qui **changent de format sans préavis**. Les
parseurs lisent leurs colonnes par ``.get()`` : une colonne renommée renvoie
``None`` en silence, et le défaut se propage jusqu'à un chiffre faux — jamais une
erreur au point de lecture. Ce module déclare, **versionné en un seul endroit**, le
schéma attendu de chaque source et échoue **explicitement en nommant la colonne
manquante**, en fin d'ingestion, avant le calcul (cf. ``docs/QUALITE-DONNEES.md``).

Deux sémantiques de « présence » selon la source :

- **CSV** (DVF) : l'en-tête fait foi — toutes les colonnes attendues doivent y être.
- **Enregistrements JSON** (DPE ADEME, Découpage) : l'API **omet les champs nuls**
  (une commune sans EPCI). Un champ n'est « manquant » que s'il est absent de **tous**
  les enregistrements — sinon c'est une valeur optionnelle, pas un renommage.

Le vocabulaire de ``periode_construction`` est **signalé**, pas bloquant : une tranche
nouvelle devient un simple niveau catégoriel de plus dans la régression (§4) — elle ne
corrompt pas le modèle comme une colonne manquante.
"""

from __future__ import annotations

import csv
import io
from collections.abc import Iterable, Mapping

# Colonnes du CSV geo-dvf lues par ``dvf.aggregate_mutations`` (§7). Une seule
# source de vérité : ces noms sont autrement dispersés dans des ``.get()``.
COLONNES_DVF: frozenset[str] = frozenset(
    {
        "id_mutation",
        "date_mutation",
        "valeur_fonciere",
        "code_commune",
        "code_postal",
        "nom_commune",
        "type_local",
        "nombre_pieces_principales",
        "surface_reelle_bati",
        "surface_terrain",
        "id_parcelle",
        "code_nature_culture",
        "adresse_numero",
        "adresse_suffixe",
        "adresse_nom_voie",
        "longitude",
        "latitude",
    }
)

# Champs ADEME demandés par ``dpe_source`` (les valeurs de son ``_FIELD_MAP``).
# Déclarés en dur ici — versionnés — et gardés alignés par un test anti-dérive.
COLONNES_DPE: frozenset[str] = frozenset(
    {
        "etiquette_dpe",
        "adresse_ban",
        "code_postal_ban",
        "periode_construction",
        "date_etablissement_dpe",
        "version_dpe",
    }
)

# Champs du référentiel communes (API Découpage) lus par ``decoupage.parse_commune``.
COLONNES_COMMUNE: frozenset[str] = frozenset(
    {"code", "nom", "codeEpci", "codesPostaux", "centre", "codeDepartement"}
)

# Vocabulaire connu des tranches ``periode_construction`` de l'ADEME (jeu
# « DPE Logements existants »). ⚠️ Relevé sur le schéma publié, non vérifiable
# hors-ligne (cf. ``dpe_source``) : corrigeable **en un seul point**, et toute
# valeur nouvelle est de toute façon **signalée** (non bloquante) plutôt qu'inférée.
VOCABULAIRE_PERIODE_CONSTRUCTION: frozenset[str] = frozenset(
    {
        "avant 1948",
        "1948-1974",
        "1975-1977",
        "1978-1982",
        "1983-1988",
        "1989-2000",
        "2001-2005",
        "2006-2012",
        "2013-2021",
        "après 2021",
    }
)


class SchemaSourceError(ValueError):
    """Une source ingérée a perdu une ou plusieurs colonnes attendues (DAT-1).

    Porte la ``source`` et l'ensemble ``colonnes_manquantes`` pour un diagnostic
    immédiat — l'inverse du ``KeyError`` anonyme à 200 lignes du point de lecture.
    """

    def __init__(self, source: str, colonnes_manquantes: Iterable[str]) -> None:
        self.source = source
        self.colonnes_manquantes: frozenset[str] = frozenset(colonnes_manquantes)
        nommees = ", ".join(sorted(self.colonnes_manquantes))
        super().__init__(f"Source {source} : colonne(s) manquante(s) : {nommees}")


def verifier_colonnes(source: str, attendues: Iterable[str], presentes: Iterable[str]) -> None:
    """Échoue si une colonne attendue manque, en la nommant (les surnuméraires OK)."""
    manquantes = frozenset(attendues) - frozenset(presentes)
    if manquantes:
        raise SchemaSourceError(source, manquantes)


def verifier_schema_dvf(csv_text: str) -> None:
    """Valide l'en-tête d'un CSV geo-dvf contre ``COLONNES_DVF``.

    Un texte sans en-tête (commune sans fichier, corps vide) n'est pas jugé : rien
    n'a été observé, on ne peut pas distinguer une colonne absente d'un renommage.
    """
    entete = next(csv.reader(io.StringIO(csv_text)), None)
    if not entete:
        return
    verifier_colonnes("DVF", COLONNES_DVF, entete)


def verifier_schema_json(
    source: str, attendues: Iterable[str], enregistrements: Iterable[Mapping[str, object]]
) -> None:
    """Valide des enregistrements JSON contre un schéma, sur l'**union des clés**.

    L'API omet les champs nuls : un champ n'est manquant que s'il est absent de tous
    les enregistrements. Une collection vide n'est pas jugée (rien d'observé).
    """
    presentes: set[str] = set()
    for enregistrement in enregistrements:
        presentes.update(enregistrement.keys())
    if not presentes:
        return
    verifier_colonnes(source, attendues, presentes)


def periodes_inconnues(valeurs: Iterable[str | None]) -> set[str]:
    """Valeurs de ``periode_construction`` hors vocabulaire connu (vides ignorées)."""
    inconnues: set[str] = set()
    for valeur in valeurs:
        if valeur is None:
            continue
        nettoyee = valeur.strip()
        if nettoyee and nettoyee not in VOCABULAIRE_PERIODE_CONSTRUCTION:
            inconnues.add(nettoyee)
    return inconnues
