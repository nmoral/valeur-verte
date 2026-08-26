#!/usr/bin/env bash
# Prépare l'environnement de test PHP de `app/` dans un worktree, de zéro jusqu'à
# une suite PHPUnit verte. Idempotent : sûr à rejouer, ne refait que ce qui manque.
#
# À LANCER DEPUIS LA RACINE DU WORKTREE (là où vivent Makefile + scripts/app-dev.sh),
# jamais depuis la racine `valeur-verte-root/` (qui n'est pas un dépôt git).
#
# Usage :
#   .claude/skills/vv-init-env-test/scripts/init-test-env.sh          # prépare, n'exécute pas la suite
#   .claude/skills/vv-init-env-test/scripts/init-test-env.sh --smoke  # + un test témoin rapide
#   .claude/skills/vv-init-env-test/scripts/init-test-env.sh --full   # + la suite complète (memory_limit=-1)
set -euo pipefail

MODE="${1:-prepare}"

# --- Garde-fou : sommes-nous à la racine d'un worktree du dépôt ? ---------------
if [[ ! -f Makefile || ! -x scripts/app-dev.sh ]]; then
  echo "✗ Lancer ce script depuis la racine d'un worktree (Makefile + scripts/app-dev.sh introuvables ici)." >&2
  echo "  cwd actuel : $(pwd)" >&2
  exit 1
fi

info() { printf '\n\033[1;34m▸ %s\033[0m\n' "$1"; }

# --- 1. Image applicative + base du worktree -----------------------------------
# `make stack-up` construit l'image prod (cache Docker ⇒ rapide si rien n'a bougé),
# démarre db + app et migre la base DEV. On en a besoin pour deux choses : l'image
# (dans laquelle app-dev.sh joue les commandes PHP) et la base `db` du worktree.
info "Stack du worktree (image + base)"
make stack-up

# --- 2. vendor/ AVEC les dev-deps ----------------------------------------------
# Piège coûteux : l'image prod n'embarque PAS phpunit/phpstan/cs-fixer (build
# --no-dev). Un `docker cp` de son vendor donnerait donc un vendor sans outils de
# test. On peuple un vendor local complet via composer DANS l'image (PHP 8.4).
if [[ -x app/vendor/bin/phpunit ]]; then
  info "vendor/ déjà pourvu des dev-deps (phpunit présent) — on garde"
else
  info "Installation des dépendances (dev incluses) via l'image"
  scripts/app-dev.sh composer install --no-interaction
fi

# --- 3. Migration de la base de TEST -------------------------------------------
# `make stack-up` n'a migré que la base DEV (valeur_verte). La base de test
# (valeur_verte_test, créée vide par le conteneur db) doit être migrée à part,
# sinon la suite part sur des centaines d'erreurs « relation "utilisateur"/"tenant"
# … does not exist ». app-dev.sh tourne en APP_ENV=test ⇒ Doctrine vise le suffixe
# _test automatiquement.
info "Migration de la base de test (valeur_verte_test)"
scripts/app-dev.sh php bin/console doctrine:migrations:migrate --no-interaction

# --- 4. Purge du cache de test compilé -----------------------------------------
# Après un changement de vendor (ou d'un gabarit Twig), le conteneur/templates
# compilés dans var/cache/test sont périmés et jettent des erreurs trompeuses au
# rendu (p.ex. « Class "PHPStan\PhpDocParser\Lexer\Lexer" not found » dans les
# gabarits admin). On le vide systématiquement.
info "Purge de app/var/cache/test"
rm -rf app/var/cache/test

# NB : le schéma `data` (data.commune, coefficient_maille, publication_millesime…)
# n'est PAS créé ici. Les tests qui en dépendent le provisionnent eux-mêmes depuis
# les migrations du pipeline via le trait ProvisionneModelePublie — à condition que
# `data/` soit monté (VV_DATA_DIR), ce que app-dev.sh fait déjà. Rien à ajouter.

echo
echo "✓ Environnement de test prêt."
echo "  Suite complète (mpdf a besoin de mémoire, d'où memory_limit=-1) :"
echo "    scripts/app-dev.sh php -d memory_limit=-1 vendor/bin/phpunit"
echo "  Un seul test :"
echo "    scripts/app-dev.sh vendor/bin/phpunit --filter testNom"

case "$MODE" in
  --smoke)
    info "Test témoin (tests/Publication — provisionne le schéma data)"
    scripts/app-dev.sh php -d memory_limit=-1 vendor/bin/phpunit tests/Publication
    ;;
  --full)
    info "Suite complète"
    # Les lignes [error]/[critical] loguées sont des exceptions ATTENDUES par des
    # tests (404/429 asservis) : seul le récapitulatif « OK » / « FAILURES » compte.
    scripts/app-dev.sh php -d memory_limit=-1 vendor/bin/phpunit
    ;;
  prepare) : ;;
  *)
    echo "Argument inconnu : $MODE (attendu : rien | --smoke | --full)" >&2
    exit 2
    ;;
esac
