# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Règles de travail (Valeur verte)

Ce fichier fait autorité pour toute contribution au dépôt, humaine ou assistée.
Contexte produit : voir [`README.md`](README.md) ; backlog : [`docs/BACKLOG.md`](docs/BACKLOG.md).

## Règles de calcul du moteur (toujours en contexte)

Le référentiel exhaustif des règles du moteur (filtres, modèle, pondération, seuils,
bandes, invariants, config gagnante) est importé ici :

@docs/MOTEUR-REGLES-CALCUL.md

**Règle obligatoire — toute modification d'une règle de calcul du moteur doit être
inscrite dans [`docs/MOTEUR-REGLES-CALCUL.md`](docs/MOTEUR-REGLES-CALCUL.md), dans le
même commit que le code.** Est concerné tout changement de : filtre/éligibilité,
bornes, modèle de régression, variables, pondération σ, rayon adaptatif, mode de
couverture, écrêtage, bandes DPE, seuils de publication, invariants, validation
croisée, config par défaut, ou format de sortie `coefficient_maille`. Une PR touchant
le moteur sans mise à jour de ce référentiel est **incomplète** : le document doit
décrire le moteur **tel qu'il tourne**, jamais une version périmée.

## Structure du dépôt (monorepo)

| Dossier | Techno | Rôle |
|---|---|---|
| `app/` | PHP 8.4 / Symfony 8.1 | Widget public, mode audit, back-office pro, admin interne (schéma `app`) |
| `data/` | Python 3.11+ | Ingestion DVF/DPE/BAN, régressions, écriture des coefficients (schéma `data`) |

Contrat entre les deux : `data` est **lecture seule** pour `app` ; chacun gère ses propres migrations.

### Architecture de `app/` (Symfony)

Organisation **par domaine**, pas par couche technique :

- `src/<Domaine>/<Concept>/` — le cœur métier. Ex. `src/BackOffice/{Rapport,Branding,GrilleCout,Bouquet}/`.
  On y trouve des **objets-valeur** (`Montant`, `Couleur`, `PeriodeValidite`, l'enum `Dpe`…) et,
  pour les agrégats persistés, une **entité Doctrine + son `ServiceEntityRepository`**
  (`RapportRepository`, `BrandingRepository`). Les valeurs par défaut du produit sont portées par
  des **fabriques statiques** — `GrilleCout::parDefautNationale()`, `Bouquet::renovationAmpleurStandard()`,
  `Branding::parDefaut()` — c'est le socle du principe « un rapport avant toute configuration » :
  un repository renvoie toujours un objet exploitable même sans rien en base (`BrandingRepository::courant()`).
- `src/Controller/<Domaine>/` — contrôleurs **invokables et fins** (`#[Route]` en attribut, une action
  `__invoke`, quelques lignes de glue) qui délèguent au domaine et rendent un template. Aucune logique métier.
- `templates/<domaine>/` — Twig en `snake_case`, avec un layout par zone (`back_office/base.html.twig`).

**Deux schémas PostgreSQL dans une base unique** (contrat `app`/`data`) :

- Le rôle de connexion `app` a `search_path = app` ; Doctrine ne gère **que** le schéma `app`.
  Le `schema_filter: ~^[^.]+$~` (dans `config/packages/doctrine.yaml`) ne retient que les tables
  **non qualifiées** → `data`, `public`, `postgis` restent invisibles du diff et des migrations.
- `App\Doctrine\DeclareSchemasExistants` (listener `postGenerateSchema`) déclare les schémas déjà
  présents en base pour que `doctrine:migrations:diff` ne génère pas de `CREATE SCHEMA` superflu.
- L'init de la base (`infra/db-init/10-init-app.sh`, joué à la création du volume) crée le rôle `app`,
  le schéma `app` (en écriture) et les `GRANT SELECT` sur `data`, dans la base de dev **et** dans
  `valeur_verte_test`.

---

## 1. TDD — non négociable

On n'écrit **jamais** de code de production sans un test qui échoue d'abord. Boucle stricte
**Red → Green → Refactor**, et **un commit à chaque étape** :

1. **Red** — écrire un test qui décrit le comportement voulu et **échoue**.
   → `git commit -m "test(<scope>): <quoi> (red)"`
2. **Green** — écrire le **minimum** de code pour faire passer le test.
   → `git commit -m "feat(<scope>): <quoi> (green)"` (ou `fix(...)`)
3. **Refactor** — nettoyer le code **en gardant les tests verts** (aucun changement de comportement).
   → `git commit -m "refactor(<scope>): <quoi>"`

Règles :
- Petits pas, petits commits. Un cycle Red/Green/Refactor par comportement.
- Le commit **red** peut contenir un test qui échoue (c'est voulu) ; les commits **green** et
  **refactor** doivent laisser la suite de tests **verte**.
- Pas de code mort « au cas où » : on n'ajoute que ce qu'un test exige.

### Convention de commit (Conventional Commits)

`<type>(<scope>): <sujet>` — types : `test`, `feat`, `fix`, `refactor`, `chore`, `docs`, `ci`, `perf`.
`<scope>` = domaine concerné (`data`, `moteur`, `calcul-eco`, `audit`, `widget`, `back-office`,
`admin`, `infra`…). Référencer l'issue dans le corps ou le pied : `Refs #42` / `Closes #42`.

---

## 2. Une branche par issue

- Toute tâche part d'une **issue** (voir le backlog). Une issue = **une branche** = une PR.
- Créer la branche **depuis `main`** à jour :
  `git switch main && git pull && git switch -c issue-<num>-<slug>`
  (ex. `issue-20-rayon-adaptatif`).
- La branche reste **focalisée sur son issue** : pas de changements hors périmètre.
- Ouvrir une **Pull Request vers `main`** ; le corps contient `Closes #<num>`.
- Faire évoluer le **label de statut** de l'issue au fil de l'eau :
  `statut: à faire` → `statut: en cours` → `statut: en revue` → (fermeture) `statut: fini`.
  `statut: bloqué` si dépendance non satisfaite.
- **Ne pas committer directement sur `main`** (sauf amorçage initial explicitement demandé).
  `main` n'accepte que des merges de PR dont les portes qualité sont vertes.

---

## 3. Portes qualité (obligatoires)

Pour **chaque** changement, ces cinq portes doivent être vertes. Point d'entrée unique : le `Makefile`.

| Porte | `app/` (PHP) | `data/` (Python) | Commande |
|---|---|---|---|
| **Lint / format** | PHP-CS-Fixer (règles `@Symfony`) | Ruff (lint + format) | `make lint` |
| **Analyse statique** | PHPStan niveau `max` | mypy `--strict` | `make stan` |
| **Tests** | PHPUnit | pytest | `make test` |
| **Mutation testing** | Infection (MSI ≥ 80 %) | mutmut | `make mutation` |

- **Avant chaque commit `green`/`refactor`** : au minimum `make lint stan test` sur le périmètre touché
  (raccourci : `make check`).
- **Avant d'ouvrir/mettre à jour une PR** : `make check` **puis** `make mutation` (le mutation testing
  est plus lent, on ne le lance pas à chaque micro-commit mais il doit être vert avant la revue).
- Les portes ne se contournent pas :
  - pas de `@phpstan-ignore` / `# type: ignore` / règle désactivée **sans commentaire justifiant**,
  - pas de test `skip`/`incomplete` laissé en place sans issue de suivi,
  - on ne baisse pas un seuil (niveau PHPStan, MSI Infection) pour « faire passer ».

### Objectif mutation testing
Le mutation testing mesure la **qualité des tests**, pas seulement leur couverture. Cible :
**MSI couvert ≥ 80 %** par maille de code touchée. Un mutant survivant = un test manquant ou trop faible ;
on ajoute le test (retour en boucle TDD) plutôt que d'ignorer le mutant.

---

## 4. Commandes

Depuis la racine du dépôt (voir `Makefile` pour le détail) :

```bash
make install     # installe les dépendances (composer + python)
make lint        # PHP-CS-Fixer (dry-run) + Ruff (check + format --check)
make lint-fix    # applique les corrections de style automatiques
make stan        # PHPStan + mypy
make test        # PHPUnit + pytest
make mutation    # Infection + mutmut
make check       # lint + stan + test  (porte avant commit)
```

Cibles par sous-projet également disponibles : `make app-test`, `make data-stan`, etc.

### Poste de dev sans PHP — tout passe par Docker

Les cibles `make app-*` supposent PHP 8.4 + Composer **sur le poste** (comme en CI, via
`setup-php`). En leur absence, on joue les portes `app/` **dans l'image de la stack**
(`infra-app`, qui porte `intl` + `pdo_pgsql`), en montant le code local (le `vendor/`
local contient déjà les dev-deps) et en tournant sous l'uid de l'hôte pour que
`var/cache` reste inscriptible :

```bash
docker compose -f infra/docker-compose.yml run --rm --no-deps \
  -v "$PWD/app:/app" -w /app -u "$(id -u):$(id -g)" \
  -e APP_ENV=test \
  -e DATABASE_URL="postgresql://app:app@db:5432/valeur_verte?serverVersion=16&charset=utf8" \
  --entrypoint "" app  vendor/bin/phpunit
```

En environnement `test`, Doctrine **ajoute le suffixe `_test`** (`dbname_suffix`) :
l'URL pointe donc toujours la base `valeur_verte`, jamais `valeur_verte_test` en dur.
Remplacer la dernière commande par `vendor/bin/phpstan analyse`,
`vendor/bin/php-cs-fixer fix --dry-run --diff` ou `php bin/console …` selon la porte.
La base de test doit être migrée une fois : `… php bin/console doctrine:migrations:migrate`.

**Lancer un seul test** : `vendor/bin/phpunit --filter testNomDuTest`
(ou `vendor/bin/phpunit tests/BackOffice/Rapport/RapportTest.php`).

### Stack locale (app tournante pour vérification manuelle / Playwright)

Le `Makefile` porte une stack Docker (PostGIS partagé + app FrankenPHP) — voir la section
« Stack locale » du fichier :

```bash
make stack-up      # build + démarre db & app (image prod), migrations → http://127.0.0.1:8000
make stack-dev     # itération : code app/ monté (pas de rebuild), APP_ENV=dev, sous-domaines
make stack-migrate # rejoue les migrations sur la base en cours
make stack-seed    # insère quelques rapports de démo (dont un lead)
make stack-logs    # suit les logs de l'app
make stack-down    # arrête (conserve les données)
make stack-reset   # arrête ET efface la base (repart de zéro)
```

`stack-up` exerce l'artefact de prod (le même qu'au VPS). `stack-dev` monte `app/` en
bind-mount et bascule en `APP_ENV=dev` : les changements de code sont pris sans
reconstruire l'image. Les zones vivent sur des **sous-domaines** de `%app.domaine%`
(défaut `valeurverte.localhost`, résolu vers 127.0.0.1 par les navigateurs) :
`app.` (espace pro), `admin.` (admin interne), `widget.` (widget public).

Vérifier une page, c'est vérifier qu'elle **fonctionne** (contenu, action attendue),
pas seulement qu'elle répond 200.

---

## 5. Exploitation — les traitements longs tournent **sur le VPS**

Toute tâche qui travaille sur la base de production — ingestion, calcul, dump, migration —
s'exécute **sur le VPS, dans un conteneur détaché**. Jamais depuis un poste de développement
à travers le tunnel SSH.

**Pourquoi** : le tunnel meurt avec la session qui l'a ouvert — terminal fermé, poste en veille,
session Claude Code terminée. Le traitement meurt avec lui. Une ingestion de plusieurs heures
coupée à 70 % laisse la base dans un état hybride et **repart de zéro**. C'est exactement ce qui
s'est produit sur les départements limitrophes (#91), deux fois.

Forme attendue :

```bash
# Lancer — le conteneur est nommé pour être retrouvable, et n'est pas auto-supprimé
# afin que ses logs et son code de sortie survivent à la fin du traitement.
ssh vps 'cd /opt/valeur-verte && docker compose run -d --name ingest-09 \
  migrate valeur_verte_data.cli ingest --departement 09 --millesime 2024'

# Suivre (depuis n'importe quelle session, plus tard, depuis une autre machine)
ssh vps 'docker logs -f ingest-09'

# Constater la fin et nettoyer
ssh vps 'docker inspect -f "{{.State.Status}} {{.State.ExitCode}}" ingest-09'
ssh vps 'docker rm ingest-09'
```

`migrate` est le service porteur de l'image du pipeline ; l'`ENTRYPOINT` étant `python -m`,
la commande passée est le module à jouer. Le cycle de vie du conteneur est celui du démon
Docker du VPS, pas celui de la connexion SSH : la déconnexion n'a aucun effet sur lui.

Le **tunnel SSH** (`ssh -f -N -L 15432:127.0.0.1:5432 vps`) reste légitime pour la
**consultation** : psql, client graphique, requête de contrôle, export ponctuel. Autrement dit
tout ce qu'une coupure permet de relancer sans conséquence. Dès qu'un traitement écrit et dure,
il passe sur le VPS.

**À annoncer explicitement** : en lançant un traitement long, dire **où il tourne** et **comment
en suivre l'avancement** sans dépendre de la session en cours. Un traitement dont la survie
dépend de la fenêtre ouverte n'est pas lancé, il est seulement commencé.

---

## 6. Amorçage des sous-projets (une fois)

Le harnais qualité (`Makefile` + fichiers de config) est en place. Pour matérialiser chaque
sous-projet la première fois :

- **`app/`** : `cd app && composer install` (le squelette Symfony s'ajoute via
  `composer require symfony/framework-bundle …` ou `symfony new`). Les configs
  `phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `phpunit.dist.xml`, `infection.json5` sont fournies.
- **`data/`** : `cd data && python -m venv .venv && . .venv/bin/activate && pip install -e '.[dev]'`.
  Toute la config outillage est dans `pyproject.toml`.

---

## 7. Rappels produit (à ne jamais casser)

Ces invariants viennent du cadrage et sont vérifiés par des tests :

- Le **coefficient statistique n'est jamais modifiable** ; l'ajustement du pro est une **ligne
  séparée motivée**, en mode audit uniquement.
- Toute restitution affiche **n, intervalle de confiance, rayon, millésime**.
- **Aucun `UPDATE` destructif** sur les barèmes/grilles : versionnement `valide_du`/`valide_au`.
- Un rapport est **reproductible à l'identique** via son instantané JSONB.
- Coefficients **pré-calculés par maille**, jamais à la volée.

---

## 8. Attendu de Claude

- Suivre le cycle **Red → Green → Refactor** avec **un commit par étape**, sans exception.
- **Une branche par issue**, PR vers `main`, statut de l'issue tenu à jour.
- Lancer `make check` avant de déclarer une étape terminée, `make mutation` avant la revue ;
  rapporter honnêtement l'état (tests rouges = le dire).
- Ne pas affaiblir les portes qualité ni contourner un outil pour « faire vert ».
- Rester dans le périmètre de l'issue courante ; hors périmètre → nouvelle issue.
- **Lancer tout traitement long sur le VPS** (§5), jamais à travers le tunnel SSH, et
  indiquer comment en suivre l'avancement depuis une autre session.
- **Toute modification d'une règle de calcul du moteur → mise à jour de
  [`docs/MOTEUR-REGLES-CALCUL.md`](docs/MOTEUR-REGLES-CALCUL.md) dans le même commit**
  (voir « Règles de calcul du moteur »). Sans ça, la contribution est incomplète.
