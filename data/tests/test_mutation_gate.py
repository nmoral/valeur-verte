"""Tests de la porte de MSI (#151).

Le MSI est calculé **tués / (tués + survivants)** : les mutants « no tests »
(🫥/🔇) sont exclus du dénominateur, pour ne pas pénaliser le code couvert
seulement par l'intégration (ex. ``cli.main``, sans DB dans ``data-quality``).
Les timeouts (⏰) comptent comme détectés.
"""

from __future__ import annotations

import sys
from pathlib import Path

_ROOT = Path(__file__).resolve().parents[1]  # data/
sys.path.insert(0, str(_ROOT / "tools"))

from mutation_gate import compute_score  # noqa: E402


def _tally(killed: int, survived: int, no_tests: int = 0, timeout: int = 0) -> str:
    total = killed + survived + no_tests + timeout
    return (
        f"⠇ {total}/{total}  🎉 {killed} 🫥 {no_tests}  ⏰ {timeout}  "
        f"🤔 0  🙁 {survived}  🔇 0  🧙 0"
    )


def test_exclut_les_no_tests_du_denominateur() -> None:
    # 8 tués, 2 survivants, 5 sans test → 8 / (8+2) = 80 %, pas 8/15.
    caught, decisive = compute_score(_tally(killed=8, survived=2, no_tests=5))
    assert (caught, decisive) == (8, 10)


def test_timeout_compte_comme_detecte() -> None:
    caught, decisive = compute_score(_tally(killed=7, survived=2, timeout=1))
    assert (caught, decisive) == (8, 10)


def test_aucun_mutant_decisif_ne_divise_pas_par_zero() -> None:
    # Fichier couvert seulement par l'intégration : que des « no tests ».
    caught, decisive = compute_score(_tally(killed=0, survived=0, no_tests=6))
    assert (caught, decisive) == (0, 0)
