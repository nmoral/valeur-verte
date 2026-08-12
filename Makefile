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
	cd $(APP) && vendor/bin/phpunit --coverage-xml=var/cov/coverage-xml --log-junit=var/cov/junit.xml \
		&& vendor/bin/infection --coverage=var/cov --skip-initial-tests --min-covered-msi=80 --threads=max

data-mutation:
	cd $(DATA) && ./tools/run-mutation.sh 80

# ---- Porte agrégée (à lancer avant chaque commit green/refactor) ----
check: lint stan test

# ---- Stack locale (PostGIS partagé + app FrankenPHP) ----
# Lance la base et l'application dans Docker, applique les migrations, et expose
# l'app sur http://127.0.0.1:8000. Ne nécessite ni PHP ni Composer sur le poste :
# l'image se construit toute seule. La base prépare aussi valeur_verte_test.
COMPOSE := docker compose -f $(INFRA)/docker-compose.yml
# Variante dev : monte le code (pas de rebuild à chaque changement), APP_ENV=dev.
COMPOSE_DEV := docker compose -f $(INFRA)/docker-compose.yml -f $(INFRA)/docker-compose.dev.yml

.PHONY: stack-up stack-dev stack-image stack-down stack-reset stack-migrate stack-seed stack-logs

stack-up:
	$(COMPOSE) up -d --build
	$(COMPOSE) exec -T app php bin/console doctrine:migrations:migrate --no-interaction
	@echo "App disponible sur http://127.0.0.1:8000 (back-office : /back-office/couts, /back-office/bouquets, /back-office/rapports, /back-office/marque)"

# Stack de développement : le code app/ est monté dans le conteneur, donc un
# changement de code est reflété sans reconstruire l'image (APP_ENV=dev). L'image
# (socle PHP + extensions) est réutilisée telle quelle : le vendor/ local est monté,
# donc PAS de « composer install » ni de rebuild réseau. La construire une fois via
# « make stack-up » (ou « make stack-image ») si elle n'existe pas encore.
stack-dev:
	$(COMPOSE_DEV) up -d
	$(COMPOSE_DEV) exec -T app php bin/console doctrine:migrations:migrate --no-interaction
	@echo "App (dev) sur http://app.valeurverte.localhost:8000 et http://admin.valeurverte.localhost:8000"

# Construit (ou reconstruit) l'image applicative seule.
stack-image:
	$(COMPOSE) build app

# Applique les migrations sur la base de la stack en cours.
stack-migrate:
	$(COMPOSE) exec -T app php bin/console doctrine:migrations:migrate --no-interaction

# Insère quelques rapports de démonstration (dont un lead) pour peupler la liste.
stack-seed:
	$(COMPOSE) exec -T -e PGPASSWORD=app db psql -U app -d valeur_verte -c "INSERT INTO rapport (reference,cree_le,source,adresse,dpe_avant,dpe_apres,valeur_verte_estimee,pdf_url,contact_nom,contact_email,contact_telephone) VALUES ('2026-0841','2026-08-01 09:00','audit','12 rue des Écoles, Baziège (31)','E','C',52610,'/rapports/2026-0841.pdf',NULL,NULL,NULL), ('2026-0900','2026-08-10 09:00','widget','4 chemin de la Garrigue, Montgiscard (31)','F','C',40700,'/rapports/2026-0900.pdf','Mme Cazeneuve','cazeneuve@example.com',NULL) ON CONFLICT (reference) DO NOTHING;"

stack-logs:
	$(COMPOSE) logs -f app

# Arrête la stack en conservant les données.
stack-down:
	$(COMPOSE) down

# Arrête la stack ET efface la base (repart de zéro).
stack-reset:
	$(COMPOSE) down -v
