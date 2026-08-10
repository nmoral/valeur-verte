"""Porte de mutation testing : échoue si le MSI est sous le seuil.

Lit la sortie de ``mutmut run`` sur l'entrée standard, en extrait le dernier
décompte (``N/N  🎉 tués … 🙁 survivants``) et calcule le score MSI.

Usage :
    (mutmut run 2>&1 || true) | tr '\\r' '\\n' | python tools/mutation_gate.py 80
"""

from __future__ import annotations

import re
import sys


def compute_score(log: str) -> tuple[int, int]:
    """Retourne ``(tués, total)`` d'après le dernier décompte mutmut du log."""
    tally = [line for line in log.splitlines() if "🎉" in line and "/" in line]
    if not tally:
        raise SystemExit("Décompte mutmut introuvable dans la sortie.")
    line = tally[-1]
    total = re.search(r"(\d+)\s*/\s*(\d+)", line)
    killed = re.search(r"🎉\s*(\d+)", line)
    if total is None or killed is None:
        raise SystemExit("Décompte mutmut illisible.")
    return int(killed.group(1)), int(total.group(2))


def main() -> int:
    threshold = float(sys.argv[1]) if len(sys.argv) > 1 else 80.0
    killed, total = compute_score(sys.stdin.read())
    msi = 100.0 * killed / total if total else 0.0
    print(f"MSI = {killed}/{total} = {msi:.1f} % (seuil {threshold:.0f} %)")
    return 0 if msi >= threshold else 1


if __name__ == "__main__":
    raise SystemExit(main())
