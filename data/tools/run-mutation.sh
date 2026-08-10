#!/usr/bin/env sh
# Lance mutmut et applique la porte de MSI (défaut 80 %). À exécuter depuis data/.
set -eu
threshold="${1:-80}"
rm -rf mutants .mutmut-cache 2>/dev/null || true
# mutmut sort en erreur s'il reste des mutants survivants (dont les équivalents) :
# on neutralise son code de sortie et on juge sur le MSI.
(mutmut run 2>&1 || true) | tr '\r' '\n' | python tools/mutation_gate.py "$threshold"
