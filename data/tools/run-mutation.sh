#!/usr/bin/env sh
# Lance mutmut et applique la porte de MSI (défaut 80 %). À exécuter depuis data/.
#
# Usage :
#   run-mutation.sh [seuil] [fichiers...]
#
# Sans fichiers : mute tout `src/` (run complet, ex. en local).
# Avec fichiers : ne mute que ces fichiers `src/**.py` (scopé aux fichiers changés,
# CLAUDE.md §3 « MSI par maille touchée »). En CI, SCOPE_REQUIRED=1 fait ignorer la
# mutation quand aucun fichier `src/` n'a changé (rien à muter).
set -eu
threshold="${1:-80}"
shift 2>/dev/null || true

rm -rf mutants .mutmut-cache 2>/dev/null || true

# Le helper réécrit pyproject.toml sur place (only_mutate) ; on sauvegarde avant
# et on restaure en sortie. Code 0 = fichiers scopés, 2 = aucun fichier src/.
cp pyproject.toml pyproject.toml.mutbak
trap 'mv -f pyproject.toml.mutbak pyproject.toml 2>/dev/null || true' EXIT

if python tools/scope_mutation.py "$@"; then
    :  # mutation restreinte aux fichiers changés
elif [ "${SCOPE_REQUIRED:-0}" = "1" ]; then
    echo "Aucun fichier src/**.py modifié — mutation testing ignoré."
    exit 0
fi

# mutmut sort en erreur s'il reste des mutants survivants (dont les équivalents) :
# on neutralise son code de sortie et on juge sur le MSI.
(mutmut run 2>&1 || true) | tr '\r' '\n' | python tools/mutation_gate.py "$threshold"
