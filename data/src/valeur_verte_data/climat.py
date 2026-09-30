"""Référentiel climat (#341, Lot 1) : zone climatique RT2012 par département.

Marqueur **contextuel** départemental du bloc « Pourquoi ici ? » : le climat
explique *pourquoi* la valeur verte varie (un hiver rigoureux valorise l'isolation),
sans jamais chiffrer — c'est une hypothèse. Trois zones réglementaires, mappées
sur un libellé lisible :

- **H1 → ``froid``** (nord-est et centre, climat continental) ;
- **H2 → ``tempere``** (ouest / sud-ouest, climat océanique) ;
- **H3 → ``doux``** (pourtour méditerranéen et Corse).

Donnée de **référence figée, embarquée et versionnée** (pas d'appel réseau) :
la répartition officielle par département du ministère de la Transition
écologique (« Répartition des départements par zone climatique », zonage RT2012,
repris par la RE2020). La Corse, listée « 20 » dans la source, est portée par ses
deux départements réels **2A** et **2B**. Les DOM ne relèvent pas de ce zonage
métropolitain : ils n'ont pas de marqueur climat.
"""

from __future__ import annotations

from .marqueurs import TYPE_CONTEXTUEL, Marqueur

SOURCE_CLIMAT = (
    "Zonage climatique RT2012 — Répartition des départements par zone climatique "
    "(ministère de la Transition écologique)"
)

# H1 — climat froid (continental).
_H1 = (
    "01",
    "02",
    "03",
    "05",
    "08",
    "10",
    "14",
    "15",
    "19",
    "21",
    "23",
    "25",
    "27",
    "28",
    "38",
    "39",
    "42",
    "43",
    "45",
    "51",
    "52",
    "54",
    "55",
    "57",
    "58",
    "59",
    "60",
    "61",
    "62",
    "63",
    "67",
    "68",
    "69",
    "70",
    "71",
    "73",
    "74",
    "75",
    "76",
    "77",
    "78",
    "80",
    "87",
    "88",
    "89",
    "90",
    "91",
    "92",
    "93",
    "94",
    "95",
)

# H2 — climat tempéré (océanique).
_H2 = (
    "04",
    "07",
    "09",
    "12",
    "16",
    "17",
    "18",
    "22",
    "24",
    "26",
    "29",
    "31",
    "32",
    "33",
    "35",
    "36",
    "37",
    "40",
    "41",
    "44",
    "46",
    "47",
    "48",
    "49",
    "50",
    "53",
    "56",
    "64",
    "65",
    "72",
    "79",
    "81",
    "82",
    "84",
    "85",
    "86",
)

# H3 — climat doux (méditerranéen). La source liste « 20 Corse » ; on la porte
# sur les deux départements réels 2A (Corse-du-Sud) et 2B (Haute-Corse).
_H3 = ("06", "11", "13", "2A", "2B", "30", "34", "66", "83")

_ZONE_PAR_DEPARTEMENT: dict[str, str] = {
    **dict.fromkeys(_H1, "froid"),
    **dict.fromkeys(_H2, "tempere"),
    **dict.fromkeys(_H3, "doux"),
}


def marqueur_climat(code_departement: str) -> Marqueur | None:
    """Le marqueur climat du département, ou ``None`` hors zonage métropolitain."""
    valeur = _ZONE_PAR_DEPARTEMENT.get(code_departement)
    if valeur is None:
        return None

    return Marqueur(
        code_insee=None,
        code_departement=code_departement,
        nom="climat",
        valeur=valeur,
        type_marqueur=TYPE_CONTEXTUEL,
        source=SOURCE_CLIMAT,
    )


def departements_couverts() -> frozenset[str]:
    """Les départements portant une zone climatique (garde d'exhaustivité)."""
    return frozenset(_ZONE_PAR_DEPARTEMENT)
