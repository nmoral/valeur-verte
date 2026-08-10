"""Normalisation et clé d'appariement d'adresses entre DVF et DPE (hors-ligne).

Il n'existe aucune clé commune entre les ventes (DVF) et les DPE (ADEME) :
l'appariement se fait « au mieux » sur une clé d'adresse normalisée
``"<numéro> <voie normalisée>"``, à commune égale. Ce module ne dépend d'aucune
API et est entièrement testable hors-ligne.
"""

from __future__ import annotations

import re
import unicodedata

# Abréviations de types de voie → forme longue, pour homogénéiser les deux sources.
_STREET_ABBREVIATIONS = {
    "av": "avenue",
    "ave": "avenue",
    "bd": "boulevard",
    "bld": "boulevard",
    "che": "chemin",
    "chem": "chemin",
    "imp": "impasse",
    "pl": "place",
    "rte": "route",
    "sq": "square",
    "all": "allee",
    "lot": "lotissement",
    "res": "residence",
    "fbg": "faubourg",
    "st": "saint",
    "ste": "sainte",
}

# Mots vides, non discriminants et souvent différents d'une source à l'autre.
_STOP_WORDS = {"de", "des", "du", "la", "le", "les", "l", "d", "et", "a", "au", "aux"}

_NUMBER_RE = re.compile(r"^\s*(\d+)\s*(bis|ter|quater|b|t|q)?", re.IGNORECASE)
_POSTAL_TAIL_RE = re.compile(r"\s\d{5}\b.*$")


def strip_accents(text: str) -> str:
    """Supprime les accents/diacritiques d'une chaîne."""
    nfkd = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in nfkd if not unicodedata.combining(ch))


def normalize_street(street: str) -> str:
    """Normalise un nom de voie : minuscule, sans accents ni ponctuation,
    abréviations développées et mots vides retirés.
    """
    if not street:
        return ""
    text = re.sub(r"[^a-z0-9]+", " ", strip_accents(str(street)).lower())
    tokens = []
    for token in text.split():
        token = _STREET_ABBREVIATIONS.get(token, token)
        if token in _STOP_WORDS:
            continue
        tokens.append(token)
    return " ".join(tokens)


def normalize_number(number: object, suffix: object = None) -> str:
    """Normalise un numéro de voie (ex. ``12`` + ``bis`` → ``"12 bis"``)."""
    if number is None:
        return ""
    raw = str(number).strip()
    if raw in {"", "nan", "None"}:
        return ""
    match = re.match(r"^\s*(\d+)", raw)
    if match is None:
        return ""
    num = match.group(1)
    suf = ""
    if suffix is not None:
        suf = strip_accents(str(suffix)).lower().strip()
        if suf in {"nan", "none"}:
            suf = ""
    return f"{num} {suf}".strip() if suf else num


def address_key(number: object, street: object, suffix: object = None) -> str:
    """Construit la clé ``"<numéro> <voie normalisée>"`` (vide si voie absente)."""
    street_norm = normalize_street(street if street is not None else "")
    if not street_norm:
        return ""
    return f"{normalize_number(number, suffix)} {street_norm}".strip()


def parse_ban_address(adresse_ban: object) -> str:
    """Extrait une clé d'adresse depuis un champ BAN ``"12 Rue X 31000 Ville"``."""
    if not adresse_ban:
        return ""
    text = _POSTAL_TAIL_RE.sub("", str(adresse_ban).strip()).strip()
    if not text:
        return ""
    match = _NUMBER_RE.match(text)
    if match is None:
        return address_key("", text)
    return address_key(match.group(1), text[match.end() :], match.group(2) or "")
