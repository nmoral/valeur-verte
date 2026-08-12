"""Scope le mutation testing aux fichiers changés en injectant ``only_mutate``.

mutmut 3.7 ne lit ``only_mutate`` que depuis ``[tool.mutmut]`` du ``pyproject.toml``
(pas d'option CLI ni d'environnement) et son ``use_git_change_detection`` ne
restreint pas les mutants sur un checkout neuf. Ce script réécrit donc la section
pour ne muter que les fichiers ``src/**.py`` modifiés vs la base (#151), au lieu
de tout ``src/`` — conforme au CLAUDE.md §3 (« MSI ≥ 80 % par maille touchée »).

Usage (depuis ``data/``) :
    python tools/scope_mutation.py src/valeur_verte_data/batch.py data/src/.../cli.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

_SECTION = "[tool.mutmut]"
_ONLY_MUTATE = re.compile(r"^only_mutate\s*=.*$\n?", re.MULTILINE)


def normaliser_fichiers(fichiers: list[str]) -> list[str]:
    """Chemins ``src/**.py`` relatifs à ``data/``, préfixe repo retiré, triés et dédupliqués."""
    retenus: set[str] = set()
    for brut in fichiers:
        chemin = brut.strip()
        if not chemin:
            continue
        if chemin.startswith("data/"):
            chemin = chemin[len("data/") :]
        if chemin.startswith("src/") and chemin.endswith(".py"):
            retenus.add(chemin)
    return sorted(retenus)


def inject_only_mutate(pyproject: str, fichiers: list[str]) -> str:
    """Retourne le ``pyproject`` avec ``only_mutate`` = ``fichiers`` sous ``[tool.mutmut]``.

    Idempotent : un ``only_mutate`` existant est remplacé, jamais dupliqué. La
    section est créée si absente.
    """
    fichiers = normaliser_fichiers(fichiers)
    valeur = "only_mutate = [" + ", ".join(f'"{f}"' for f in fichiers) + "]"

    sans_ancien = _ONLY_MUTATE.sub("", pyproject)

    if _SECTION in sans_ancien:
        return sans_ancien.replace(_SECTION, f"{_SECTION}\n{valeur}", 1)

    separateur = "" if sans_ancien.endswith("\n") or not sans_ancien else "\n"
    return f"{sans_ancien}{separateur}{_SECTION}\n{valeur}\n"


def main(argv: list[str]) -> int:
    fichiers = normaliser_fichiers(argv)
    if not fichiers:
        print("Aucun fichier src/**.py à scoper.", file=sys.stderr)
        return 2
    chemin = Path("pyproject.toml")
    chemin.write_text(inject_only_mutate(chemin.read_text("utf-8"), fichiers), "utf-8")
    print("Mutation scopée sur : " + ", ".join(fichiers))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
