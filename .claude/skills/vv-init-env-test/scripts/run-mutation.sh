#!/usr/bin/env bash
# Mutation testing (Infection) sur un périmètre de `app/src`, sans PHP local.
# Reproduit la porte `make mutation` dans l'image de la stack, avec pcov installé
# à la volée. Un SEUL conteneur root fait tout : install pcov → couverture →
# Infection → chown de retour (sinon les runs suivants et `git worktree remove`
# butent sur des fichiers root).
#
# À LANCER DEPUIS LA RACINE DU WORKTREE, après init-test-env.sh (vendor + base test).
#
# Usage :
#   run-mutation.sh <src1[,src2,…]> [chemin-de-tests]
#
#   <src…>          chemins relatifs à app/ des fichiers/dossiers à muter
#                   (ex. src/Publication/IndexabiliteCommune.php,src/Publication/FicheCommune.php)
#   [chemin-tests]  optionnel : restreint la génération de couverture à ce dossier
#                   de tests (ex. tests/Publication) pour aller vite. Ne l'utiliser
#                   que si TOUS les tests couvrant le périmètre y vivent, sinon des
#                   mutants passeront pour « non couverts ». À défaut : suite entière.
#
# Exemples :
#   run-mutation.sh src/Publication/IndexabiliteCommune.php tests/Publication
#   run-mutation.sh src/BackOffice/Restitution
set -euo pipefail

FILTER="${1:-}"
TEST_PATH="${2:-}"

if [[ -z "$FILTER" ]]; then
  echo "✗ Périmètre manquant. Usage : run-mutation.sh <src1[,src2,…]> [chemin-de-tests]" >&2
  exit 1
fi
if [[ ! -f Makefile || ! -x scripts/app-dev.sh ]]; then
  echo "✗ Lancer depuis la racine d'un worktree du dépôt." >&2
  exit 1
fi

UID_HOTE="$(id -u)"; GID_HOTE="$(id -g)"

# Env du worktree : sans le bon projet compose + réseau, le conteneur root ne
# résout pas l'hôte `db` (des dizaines de tests fonctionnels en erreur sous
# couverture → MSI faussé) et peut collisionner sur le port 5432 d'un autre worktree.
eval "$(scripts/worktree-env.sh --export)"
docker compose -p "$COMPOSE_PROJECT_NAME" -f infra/docker-compose.yml up -d --wait db >/dev/null

# Tout dans le MÊME `run` : pcov installé dans un conteneur n'existe pas dans le
# suivant (chaque `run` est un conteneur neuf).
docker compose -p "$COMPOSE_PROJECT_NAME" -f infra/docker-compose.yml run --rm --no-deps \
  -u 0:0 \
  -v "$PWD/app:/app" -v "$PWD/data:/pipeline:ro" -w /app \
  -e APP_ENV=test \
  -e DATABASE_URL="postgresql://app:app@db:5432/valeur_verte?serverVersion=16&charset=utf8" \
  -e VV_DATA_DIR=/pipeline \
  --entrypoint "" \
  app sh -c '
    set -e
    install-php-extensions pcov >/dev/null
    mkdir -p var/cov
    # memory_limit=-1 : la génération de couverture OOM sinon à 128M (mpdf, code-coverage).
    php -d pcov.enabled=1 -d memory_limit=-1 vendor/bin/phpunit \
        --coverage-xml=var/cov/coverage-xml --log-junit=var/cov/junit.xml '"${TEST_PATH:+$TEST_PATH}"'
    # --only-covering-test-cases : sans lui, chaque mutant rejoue TOUTE la suite.
    php -d memory_limit=-1 vendor/bin/infection \
        --coverage=var/cov --skip-initial-tests \
        --filter='"$FILTER"' --min-covered-msi=80 --threads=max --only-covering-test-cases
    rc=$?
    # chown de retour : sinon var/ et .phpunit.cache restent root (runs suivants et
    # git worktree remove échouent en « Permission non accordée »).
    chown -R '"$UID_HOTE:$GID_HOTE"' var .phpunit.cache 2>/dev/null || true
    exit $rc
  '
