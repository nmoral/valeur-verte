# Point d'entrée unique des portes qualité (voir CLAUDE.md §3).
# Convention : chaque cible existe en version globale et par sous-projet
# (app-* / data-* / infra-*).

.DEFAULT_GOAL := check
APP   := app
DATA  := data
INFRA := infra

.PHONY: install app-install data-install infra-install \
        lint lint-fix stan test mutation check \
        app-lint app-lint-fix app-stan app-test app-mutation \
        data-lint data-lint-fix data-stan data-test data-mutation \
        infra-lint infra-lint-fix infra-stan infra-test

# ---- Installation ----
install: app-install data-install infra-install

app-install:
	cd $(APP) && composer install

data-install:
	cd $(DATA) && python -m venv .venv && . .venv/bin/activate && pip install -e '.[dev]'

infra-install:
	cd $(INFRA) && python -m venv .venv && . .venv/bin/activate && pip install -e '.[dev]'

# ---- Lint / format ----
lint: app-lint data-lint infra-lint
lint-fix: app-lint-fix data-lint-fix infra-lint-fix

app-lint:
	cd $(APP) && vendor/bin/php-cs-fixer fix --dry-run --diff

app-lint-fix:
	cd $(APP) && vendor/bin/php-cs-fixer fix

data-lint:
	cd $(DATA) && ruff check . && ruff format --check .

data-lint-fix:
	cd $(DATA) && ruff check --fix . && ruff format .

infra-lint:
	cd $(INFRA) && ruff check . && ruff format --check .

infra-lint-fix:
	cd $(INFRA) && ruff check --fix . && ruff format .

# ---- Analyse statique ----
stan: app-stan data-stan infra-stan

app-stan:
	cd $(APP) && vendor/bin/phpstan analyse

data-stan:
	cd $(DATA) && mypy src

infra-stan:
	cd $(INFRA) && mypy tests

# ---- Tests (TDD) ----
test: app-test data-test infra-test

app-test:
	cd $(APP) && vendor/bin/phpunit

data-test:
	cd $(DATA) && pytest

# Les tests d'intégration de l'image (Docker + DATABASE_URL) se skippent d'eux-mêmes.
infra-test:
	cd $(INFRA) && pytest

# ---- Mutation testing ----
# Pas de cible infra : ce périmètre ne contient aucun code de production à muter,
# seulement des tests contraignant des fichiers de configuration.
mutation: app-mutation data-mutation

app-mutation:
	cd $(APP) && vendor/bin/infection --min-covered-msi=80 --threads=max

data-mutation:
	cd $(DATA) && ./tools/run-mutation.sh 80

# ---- Porte agrégée (à lancer avant chaque commit green/refactor) ----
check: lint stan test
