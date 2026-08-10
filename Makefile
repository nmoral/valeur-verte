# Point d'entrée unique des portes qualité (voir CLAUDE.md §3).
# Convention : chaque cible existe en version globale et par sous-projet (app-* / data-*).

.DEFAULT_GOAL := check
APP  := app
DATA := data

.PHONY: install app-install data-install \
        lint lint-fix stan test mutation check \
        app-lint app-lint-fix app-stan app-test app-mutation \
        data-lint data-lint-fix data-stan data-test data-mutation

# ---- Installation ----
install: app-install data-install

app-install:
	cd $(APP) && composer install

data-install:
	cd $(DATA) && python -m venv .venv && . .venv/bin/activate && pip install -e '.[dev]'

# ---- Lint / format ----
lint: app-lint data-lint
lint-fix: app-lint-fix data-lint-fix

app-lint:
	cd $(APP) && vendor/bin/php-cs-fixer fix --dry-run --diff

app-lint-fix:
	cd $(APP) && vendor/bin/php-cs-fixer fix

data-lint:
	cd $(DATA) && ruff check . && ruff format --check .

data-lint-fix:
	cd $(DATA) && ruff check --fix . && ruff format .

# ---- Analyse statique ----
stan: app-stan data-stan

app-stan:
	cd $(APP) && vendor/bin/phpstan analyse

data-stan:
	cd $(DATA) && mypy src

# ---- Tests (TDD) ----
test: app-test data-test

app-test:
	cd $(APP) && vendor/bin/phpunit

data-test:
	cd $(DATA) && pytest

# ---- Mutation testing ----
mutation: app-mutation data-mutation

app-mutation:
	cd $(APP) && vendor/bin/infection --min-covered-msi=80 --threads=max

data-mutation:
	cd $(DATA) && mutmut run

# ---- Porte agrégée (à lancer avant chaque commit green/refactor) ----
check: lint stan test
