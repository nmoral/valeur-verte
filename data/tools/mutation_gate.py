"""Porte de mutation testing : échoue si le MSI est sous le seuil.

Lit la sortie de ``mutmut run`` sur l'entrée standard, en extrait le dernier
décompte (``🎉 tués … ⏰ timeouts … 🙁 survivants … 🔇/🫥 sans test``) et calcule
le MSI = **détectés / (détectés + survivants)**. Les mutants « sans test » sont
exclus du dénominateur : ils signalent du code non couvert par les tests
unitaires (ex. ``cli.main``, couvert seulement par l'intégration) et ne doivent
pas faire échouer la porte quand on scope aux fichiers changés (#151). Les
timeouts comptent comme détectés.

Usage :
    (mutmut run 2>&1 || true) | tr '\\r' '\\n' | python tools/mutation_gate.py 80
"""

from __future__ import annotations

import re
import sys


def compute_score(log: str) -> tuple[int, int]:
    """Retourne ``(détectés, décisifs)`` d'après le dernier décompte mutmut du log.

    ``détectés`` = tués (🎉) + timeouts (⏰) ; ``décisifs`` = détectés + survivants (🙁).
    Les mutants sans test sont hors dénominateur.
    """
    tally = [line for line in log.splitlines() if "🎉" in line and "/" in line]
    if not tally:
        raise SystemExit("Décompte mutmut introuvable dans la sortie.")
    line = tally[-1]

    def _count(emoji: str) -> int:
        match = re.search(rf"{emoji}\s*(\d+)", line)
        return int(match.group(1)) if match else 0

    detected = _count("🎉") + _count("⏰")
    decisive = detected + _count("🙁")
    return detected, decisive


def main() -> int:
    threshold = float(sys.argv[1]) if len(sys.argv) > 1 else 80.0
    detected, decisive = compute_score(sys.stdin.read())
    msi = 100.0 if decisive == 0 else 100.0 * detected / decisive
    reste = "aucun mutant décisif" if decisive == 0 else f"{detected}/{decisive}"
    print(f"MSI = {reste} = {msi:.1f} % (seuil {threshold:.0f} %)")
    return 0 if msi >= threshold else 1


if __name__ == "__main__":
    raise SystemExit(main())
