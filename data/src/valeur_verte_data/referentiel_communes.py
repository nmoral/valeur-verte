"""Chargeur de référentiels communaux packagés (#341, Lot 1).

Les grandes listes de communes (loi Littoral, montagne…) sont **embarquées et
versionnées** en ressources texte du paquet (``referentiels/*.txt``) : un code
INSEE par ligne, les lignes ``#`` documentant et sourçant la donnée. Lues via
``importlib.resources`` — aucune dépendance réseau au chargement.
"""

from __future__ import annotations

from importlib.resources import files


def extraire_codes(texte: str) -> frozenset[str]:
    """Les codes INSEE d'un contenu de ressource : une ligne par code, en ignorant
    les lignes vides et les lignes de commentaire (``#``, entête sourçant la donnée)."""
    return frozenset(
        ligne.strip()
        for ligne in texte.splitlines()
        if ligne.strip() != "" and not ligne.startswith("#")
    )


def extraire_mapping(texte: str) -> dict[str, str]:
    """Le mapping ``code INSEE → valeur`` d'un contenu de ressource : chaque ligne
    utile porte ``CODE VALEUR`` (séparés par une espace) ; lignes vides et
    commentaires (``#``) ignorés."""
    mapping: dict[str, str] = {}
    for ligne in texte.splitlines():
        ligne = ligne.strip()
        if ligne == "" or ligne.startswith("#"):
            continue
        code, valeur = ligne.split(maxsplit=1)
        mapping[code] = valeur
    return mapping


def _lire_ressource(nom_fichier: str) -> str:
    return (files("valeur_verte_data.referentiels") / nom_fichier).read_text(encoding="utf-8")


def charger_codes_communes(nom_fichier: str) -> frozenset[str]:
    """Les codes INSEE d'une ressource packagée, hors lignes de commentaire/vides."""
    return extraire_codes(_lire_ressource(nom_fichier))


def charger_mapping_communes(nom_fichier: str) -> dict[str, str]:
    """Le mapping ``code INSEE → valeur`` d'une ressource packagée."""
    return extraire_mapping(_lire_ressource(nom_fichier))


def departement_de(code_insee: str) -> str:
    """Département d'un code INSEE de commune.

    Deux caractères en métropole (Corse ``2A``/``2B`` comprise), **trois** en
    Outre-mer (``97x``/``98x``) où le département tient sur trois chiffres.
    """
    if code_insee[:2] in ("97", "98"):
        return code_insee[:3]
    return code_insee[:2]
