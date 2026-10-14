#!/usr/bin/env bash
# Exécute une commande PHP/Symfony dans l'image de la stack (infra-app), branchée
# sur le réseau de la stack locale (service « db » accessible), avec le code local
# monté et les dev-deps du vendor/ local (installées à la première invocation si
# absentes). Tourne sous l'uid de l'hôte pour que var/cache et var/log restent
# inscriptibles.
#
# Usage :
#   scripts/app-dev.sh vendor/bin/phpunit
#   scripts/app-dev.sh php bin/console doctrine:migrations:migrate --no-interaction
#   APP_ENV=dev scripts/app-dev.sh php bin/console debug:router
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_ENV="${APP_ENV:-test}"

# Stack du worktree courant : sans nom de projet explicite, compose retomberait
# sur « infra » (le nom du répertoire du fichier compose) et cette commande
# irait taper la base d'un AUTRE worktree. Voir scripts/worktree-env.sh.
eval "$("$ROOT/scripts/worktree-env.sh" --export)"

# En environnement test, Doctrine ajoute le suffixe « _test » (dbname_suffix) :
# on pointe donc toujours la base de base « valeur_verte », jamais « _test » en dur.
DATABASE_URL="postgresql://app:app@db:5432/valeur_verte?serverVersion=16&charset=utf8"

# `data/` est monté en lecture seule : un test d'admin (#130) rejoue la migration
# des fonctions de publication depuis le fichier source du pipeline plutôt que d'en
# recopier le SQL — la copie dériverait sans que rien ne le signale. Monté sur
# /pipeline et non /data, qui est le répertoire de travail de Caddy dans l'image.
# VV_DATA_DIR dit où le trouver ; à défaut c'est la disposition du dépôt (CI).
# Chaque worktree a SA base : celle du projet doit tourner avant de lancer une
# commande qui l'interroge. `up -d --wait db` est idempotent — sans effet si elle
# est déjà debout, et attend qu'elle soit saine à la première fois.
docker compose -p "$COMPOSE_PROJECT_NAME" -f "$ROOT/infra/docker-compose.yml" \
    up -d --wait db >/dev/null

# Dépendances dev (PHPUnit, PHPStan, CS-Fixer) : l'image est bâtie --no-dev et
# vendor/ est gitignoré — sur un clone frais sans PHP sur l'hôte, « vendor/bin/* »
# n'existe nulle part. On les installe une fois dans l'image (qui porte composer),
# sous l'uid de l'hôte pour que vendor/ reste à lui. --no-scripts : pas besoin de
# la base ni du cache Symfony ici (comme la couche de deps du Dockerfile).
if [ ! -f "$ROOT/app/vendor/autoload.php" ]; then
    docker compose -p "$COMPOSE_PROJECT_NAME" -f "$ROOT/infra/docker-compose.yml" \
        run --rm --no-deps -v "$ROOT/app:/app" -w /app -u "$(id -u):$(id -g)" \
        --entrypoint "" app composer install --no-scripts --no-interaction
fi

exec docker compose -p "$COMPOSE_PROJECT_NAME" -f "$ROOT/infra/docker-compose.yml" run --rm --no-deps \
    -v "$ROOT/app:/app" -v "$ROOT/data:/pipeline:ro" -w /app \
    -u "$(id -u):$(id -g)" \
    -e APP_ENV="$APP_ENV" \
    -e DATABASE_URL="$DATABASE_URL" \
    -e VV_DATA_DIR=/pipeline \
    --entrypoint "" \
    app "$@"
