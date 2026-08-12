"""Tests du helper qui scope le mutation testing aux fichiers changés (#151).

``tools/scope_mutation.py`` injecte ``only_mutate`` sous ``[tool.mutmut]`` du
``pyproject.toml`` pour que la CI ne mute que les fichiers modifiés vs la base,
au lieu de tout ``src/`` (CLAUDE.md §3 : MSI ≥ 80 % *par maille de code touchée*).
"""

from __future__ import annotations

import sys
import tomllib
from pathlib import Path

_ROOT = Path(__file__).resolve().parents[1]  # data/
sys.path.insert(0, str(_ROOT / "tools"))

from scope_mutation import inject_only_mutate, normaliser_fichiers  # noqa: E402

_BASE = '[tool.mutmut]\nsource_paths = ["src"]\n'


def test_injecte_only_mutate_sous_la_section_mutmut() -> None:
    resultat = inject_only_mutate(_BASE, ["src/valeur_verte_data/batch.py"])

    conf = tomllib.loads(resultat)["tool"]["mutmut"]
    assert conf["only_mutate"] == ["src/valeur_verte_data/batch.py"]
    assert conf["source_paths"] == ["src"]  # inchangé


def test_normalise_retire_le_prefixe_data_et_garde_les_py_de_src() -> None:
    fichiers = normaliser_fichiers(
        [
            "data/src/valeur_verte_data/batch.py",  # préfixe repo → strip
            "src/valeur_verte_data/cli.py",  # déjà relatif à data/
            "data/tests/test_batch.py",  # hors src/ → ignoré
            "data/src/valeur_verte_data/README.md",  # pas un .py → ignoré
        ]
    )

    assert fichiers == [
        "src/valeur_verte_data/batch.py",
        "src/valeur_verte_data/cli.py",
    ]


def test_injection_idempotente_ne_duplique_pas() -> None:
    une_fois = inject_only_mutate(_BASE, ["src/a.py"])
    deux_fois = inject_only_mutate(une_fois, ["src/b.py"])

    conf = tomllib.loads(deux_fois)["tool"]["mutmut"]
    assert conf["only_mutate"] == ["src/b.py"]
    assert deux_fois.count("only_mutate") == 1


def test_cree_la_section_si_absente() -> None:
    resultat = inject_only_mutate("[project]\nname = 'x'\n", ["src/a.py"])

    conf = tomllib.loads(resultat)["tool"]["mutmut"]
    assert conf["only_mutate"] == ["src/a.py"]
