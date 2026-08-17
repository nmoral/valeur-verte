"""Client DVF : récupère le CSV geo-dvf d'une commune et l'agrège en mutations.

Etalab publie un CSV par commune et par millésime :
``.../csv/{millesime}/communes/{departement}/{code_insee}.csv``. Le département
se déduit du code INSEE (3 chiffres en Outre-mer, 2 sinon, ``2A``/``2B`` en Corse).
La logique d'agrégation (§7) vit dans ``dvf`` ; ici on ne fait que l'URL + le fetch.
"""

from __future__ import annotations

from .dvf import Mutation, mutations_from_csv
from .http import Fetcher
from .schema_source import verifier_schema_dvf

_BASE_URL = "https://files.data.gouv.fr/geo-dvf/latest/csv"


def departement(code_insee: str) -> str:
    """Département geo-dvf déduit du code INSEE (Outre-mer sur 3 chiffres)."""
    return code_insee[:3] if code_insee.startswith(("97", "98")) else code_insee[:2]


def dvf_url(millesime: int, code_insee: str) -> str:
    """URL du CSV geo-dvf pour une commune et un millésime."""
    return f"{_BASE_URL}/{millesime}/communes/{departement(code_insee)}/{code_insee}.csv"


def fetch_mutations(fetcher: Fetcher, code_insee: str, millesime: int) -> list[Mutation]:
    """Récupère et agrège les mutations DVF d'une commune (§7)."""
    csv_text = fetcher.get_text(dvf_url(millesime, code_insee))
    # DAT-1 (#124) : au bord, avant tout parsing — une colonne renommée échoue ici
    # en la nommant, plutôt que de se propager en None jusqu'à un chiffre faux.
    verifier_schema_dvf(csv_text)
    return mutations_from_csv(csv_text, millesime)
