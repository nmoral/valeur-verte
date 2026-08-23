#!/usr/bin/env bash
# Identité de la stack Docker du worktree courant : nom de projet compose et
# ports d'hôte. Source unique de vérité — le Makefile et scripts/app-dev.sh
# l'appellent tous les deux, pour qu'une commande jouée à la main et une cible
# `make` parlent au même jeu de conteneurs.
#
# Pourquoi : le nom de projet compose vaut par défaut le nom du répertoire du
# fichier compose, soit « infra » dans TOUS les worktrees. Deux worktrees qui
# démarrent la stack se partagent alors la même base et les mêmes conteneurs :
# une migration jouée d'un côté s'applique au travail de l'autre. Chaque
# worktree reçoit donc son propre projet, donc ses propres conteneurs, réseau
# et volumes (compose les préfixe par le nom de projet).
#
# Usage :
#   scripts/worktree-env.sh              # les trois variables, une par ligne
#   scripts/worktree-env.sh APP_PORT     # une seule valeur
#   eval "$(scripts/worktree-env.sh --export)"
set -euo pipefail

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NOM="$(basename "$RACINE")"

# Worktree principal ou worktree lié ? Dans le principal, le répertoire git et le
# répertoire git commun sont le même ; dans un worktree lié, le premier est un
# sous-répertoire « worktrees/<nom> » du second. Le test porte donc sur git, pas
# sur une convention de nommage de répertoire qu'un `mv` casserait.
# Hors dépôt git (image de CI qui déballe une archive, tarball), on retombe sur
# la configuration historique plutôt que d'échouer : `git rev-parse` est testé
# avant d'être lu.
if ! git -C "$RACINE" rev-parse --git-dir >/dev/null 2>&1 \
    || [ "$(git -C "$RACINE" rev-parse --git-dir)" = "$(git -C "$RACINE" rev-parse --git-common-dir)" ]; then
    # Le worktree principal garde le projet historique « infra » et ses ports :
    # renommer ici orphelinerait son volume de base (le dump de prod restauré).
    projet_defaut="infra"
    decalage=0
else
    # « valeur-verte-issue-310 » → « vv-issue-310 ».
    projet_defaut="vv-${NOM#valeur-verte-}"
    # Décalage de ports stable, dérivé du nom : le même worktree retrouve ses
    # ports à chaque démarrage (signets, config Playwright), et jamais ceux du
    # worktree principal (décalage ≥ 1).
    decalage=$(( ($(printf '%s' "$NOM" | cksum | cut -d' ' -f1) % 400) + 1 ))
fi

# Une valeur déjà présente dans l'environnement gagne : on garde la main pour
# un cas particulier (deux stacks du même worktree, port déjà pris).
projet="${COMPOSE_PROJECT_NAME:-$projet_defaut}"
port_app="${APP_PORT:-$((8000 + decalage))}"
port_pg="${POSTGRES_PORT:-$((15432 + decalage))}"

case "${1:-}" in
    COMPOSE_PROJECT_NAME) echo "$projet" ;;
    APP_PORT)             echo "$port_app" ;;
    POSTGRES_PORT)        echo "$port_pg" ;;
    --export)
        echo "export COMPOSE_PROJECT_NAME=$projet"
        echo "export APP_PORT=$port_app"
        echo "export POSTGRES_PORT=$port_pg"
        ;;
    "")
        echo "COMPOSE_PROJECT_NAME=$projet"
        echo "APP_PORT=$port_app"
        echo "POSTGRES_PORT=$port_pg"
        ;;
    *)
        echo "variable inconnue : $1 (COMPOSE_PROJECT_NAME | APP_PORT | POSTGRES_PORT)" >&2
        exit 2
        ;;
esac
