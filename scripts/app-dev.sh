#!/usr/bin/env bash
# Exécute une commande PHP/Symfony dans l'image de la stack (infra-app), branchée
# sur le réseau de la stack locale (service « db » accessible), avec le code local
# monté et les dev-deps du vendor/ local. Tourne sous l'uid de l'hôte pour que
# var/cache et var/log restent inscriptibles.
#
# Usage :
#   scripts/app-dev.sh vendor/bin/phpunit
#   scripts/app-dev.sh php bin/console doctrine:migrations:migrate --no-interaction
#   APP_ENV=dev scripts/app-dev.sh php bin/console debug:router
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_ENV="${APP_ENV:-test}"

# En environnement test, Doctrine ajoute le suffixe « _test » (dbname_suffix) :
# on pointe donc toujours la base de base « valeur_verte », jamais « _test » en dur.
DATABASE_URL="postgresql://app:app@db:5432/valeur_verte?serverVersion=16&charset=utf8"

exec docker compose -f "$ROOT/infra/docker-compose.yml" run --rm --no-deps \
    -v "$ROOT/app:/app" -w /app \
    -u "$(id -u):$(id -g)" \
    -e APP_ENV="$APP_ENV" \
    -e DATABASE_URL="$DATABASE_URL" \
    --entrypoint "" \
    app "$@"
