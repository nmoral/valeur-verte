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
| `infra/` | Python + Docker Compose | Image applicative, stack locale, provisionnement VPS, tests d'intégration de l'image |

Contrat entre `app` et `data` : `data` est **lecture seule** pour `app` ; chacun gère ses propres migrations. `infra/` a sa propre venv (`pip install -e '.[dev]'`) et passe les mêmes portes lint/stan/test (voir §3) ; ses tests d'intégration Docker se *skippent* d'eux-mêmes en l'absence de démon/`DATABASE_URL`.

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

### Architecture de `data/` (pipeline Python)

Le pipeline s'exécute par la CLI `python -m valeur_verte_data.cli <commande>`, **paramétrée
par département et millésime** — le département est l'unité de travail (#91) : plusieurs
instances tournent en parallèle sur des départements distincts sans se gêner, chacune ne
touchant que ses communes. Sous-commandes (voir `cli.py`) :

| Commande | Rôle |
|---|---|
| `ingest --departement --millesime` | Ingérer les ventes DVF d'un département |
| `compute --departement --millesime [--rapport-ecarts CSV]` | Calculer les coefficients ; `--rapport-ecarts` écrit l'écart au millésime précédent (REG-1) |
| `load-contours` / `load-epci` / `load-communes` | Charger contours (INV-3), appartenance EPCI (#169), référentiel de noms de communes (#220) |
| `audit (--departement | --tous) [--millesime] [--rapport]` | Contrôler `data.vente` en **lecture seule**, sans effet de bord |

`cli.py` ne fait que l'analyse d'arguments et le câblage de la connexion réelle ; **toute
l'orchestration vit dans `orchestration.py`** (`ingest_departement`, `compute_departement`,
`charger_*`). Les modules sont **plats** sous `src/valeur_verte_data/`, groupés par rôle :

- **Sources** (téléchargement / parsing des jeux externes) : `dvf_source`, `dpe_source`,
  `ban_source`, `decoupage_source`, `schema_source`, plus `http` (fetcher injectable).
- **Ingestion / rapprochement** : `ingest`, `cleaning`, `dvf`, `dpe`, `ban`, `matching`,
  `appariement`, `decoupage`, `multi_communes`, `limitrophes`.
- **Moteur de calcul** (les règles de `docs/MOTEUR-REGLES-CALCUL.md`) : `regression`,
  `pooling`, `radius`, `trimming`, `bandes`, `distributions`, `cross_validation`, `reg3`,
  `ruptures`, `degraded`, `invariants`, `fraicheur`, `volumetrie`, `coverage`.
- **Contrôles qualité** : `controle_qualite` et les portes `dat2` → `dat5` ; sorties `ecarts`
  (les `ecarts-<dep>-<millesime>.csv`).
- **Accès base** : `db/` — un repository par agrégat (`vente_`, `coefficient_`, `commune_`,
  `epci_`, `pool_`, `publication_`, `controle_`, `coverage_`, `limitrophe_`, `sample_`), plus
  `runner` (DSN/connexion), `migrate`/`migrations` (schéma `data`) et `rapport_batch_repository`
  (journalisation des runs d'ingestion/calcul).

`data` écrit **uniquement dans le schéma `data`** ; ses migrations lui sont propres et
indépendantes de celles d'`app`. Les traitements `ingest`/`compute` sont longs → ils tournent
**sur le VPS en conteneur détaché** (voir §5), jamais à travers le tunnel SSH.

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

## 2. Une branche — et un worktree — par tâche

- Toute tâche part d'une **issue** (voir le backlog). Une issue = **une branche** = **un
  worktree dédié** = une PR.
- **Chaque nouvelle tâche démarre dans un nouveau worktree**, jamais dans un worktree
  existant qui porte déjà une autre branche (sinon les commits atterrissent sur la
  mauvaise branche). Créer le worktree et sa branche **depuis `main`** à jour :
  `git fetch origin main && git worktree add -b issue-<num>-<slug> ../<repo>-issue-<num> origin/main`
  (ex. `../valeur-verte-issue-20`). Travailler **dans ce worktree** jusqu'au bout.
- La branche reste **focalisée sur son issue** : pas de changements hors périmètre.
- **Un jeu de conteneurs par worktree.** La stack locale (base + app) est isolée par
  worktree : nom de projet compose et ports d'hôte dérivés du répertoire courant par
  `scripts/worktree-env.sh`, que le `Makefile` et `scripts/app-dev.sh` consultent tous
  les deux. Deux tâches menées en parallèle ont donc chacune **leur base** — une
  migration jouée d'un côté ne s'applique pas au travail de l'autre — et **leurs
  ports**, sans se disputer le 8000. `make stack-info` affiche l'adresse de la stack du
  worktree courant. Le worktree principal garde le projet `infra` et les ports
  historiques (8000 / 15432) : sa base porte le dump de prod restauré, la renommer
  l'orphelinerait.
- **Avant chaque PR (ouverture ou mise à jour)** : `git fetch origin main` puis
  `git rebase origin/main`. On rejoue les portes qualité après le rebase, on ne pousse
  que sur une base à jour — la revue et la CI portent alors sur l'écart réel avec `main`,
  et on évite les conflits de merge tardifs.
- Ouvrir une **Pull Request vers `main`** ; le corps contient `Closes #<num>`.
- **Une fois la tâche terminée** (PR mergée ou abandonnée), **supprimer la stack Docker
  du worktree, puis le worktree** — dans cet ordre :

  ```bash
  make stack-purge                        # depuis le worktree : conteneurs, réseau, volumes, image
  cd ../valeur-verte                      # worktree principal
  git worktree remove ../<repo>-issue-<num>   # puis « git worktree prune » au besoin
  ```

  L'ordre n'est pas cosmétique : `stack-purge` dérive le nom du projet compose du
  répertoire du worktree. Le worktree effacé d'abord, plus rien ne rattache ses
  conteneurs à quoi que ce soit — ils continuent de tourner, gardent leurs ports et
  **redémarrent au boot** (`restart: unless-stopped`), et leur volume de base (plusieurs
  Go) reste sur le disque. Ni worktrees ni stacks dormantes ne s'accumulent.
  `docker compose ls` liste les stacks vivantes avec leur worktree d'origine : une ligne
  dont le chemin n'existe plus est une stack orpheline, à supprimer avec
  `docker compose -p <nom> down -v`.
- Faire évoluer le **label de statut** de l'issue au fil de l'eau :
  `statut: à faire` → `statut: en cours` → `statut: en revue` → (fermeture) `statut: fini`.
  `statut: bloqué` si dépendance non satisfaite.
- **Ne pas committer directement sur `main`** (sauf amorçage initial explicitement demandé).
  `main` n'accepte que des merges de PR dont les portes qualité sont vertes.

---

## 3. Portes qualité (obligatoires)

Pour **chaque** changement, ces cinq portes doivent être vertes. Point d'entrée unique : le `Makefile`.

| Porte | `app/` (PHP) | `data/` + `infra/` (Python) | Commande |
|---|---|---|---|
| **Lint / format** | PHP-CS-Fixer (règles `@Symfony`) | Ruff (lint + format) | `make lint` |
| **Analyse statique** | PHPStan niveau `max` | mypy `--strict` | `make stan` |
| **Tests** | PHPUnit | pytest | `make test` |
| **Mutation testing** | Infection (MSI ≥ 80 %) | `data/` : mutmut — pas de cible `infra` (aucun code de prod à muter) | `make mutation` |

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
(qui porte `intl` + `pdo_pgsql`), via le script dédié :

```bash
scripts/app-dev.sh vendor/bin/phpunit
```

Le script fixe le **projet compose du worktree** (sans quoi la commande irait taper la base
d'un autre worktree, cf. §2), démarre sa base si besoin, monte le code local et `data/`, et
tourne sous l'uid de l'hôte pour que `var/cache` reste inscriptible. **À la première
invocation**, si `app/vendor/` est absent (clone frais, image bâtie `--no-dev`), il lance
un `composer install` dans l'image — aucune commande préalable à retenir. Sous le capot :

```bash
docker compose -p "$(scripts/worktree-env.sh COMPOSE_PROJECT_NAME)" \
  -f infra/docker-compose.yml run --rm --no-deps \
  -v "$PWD/app:/app" -w /app -u "$(id -u):$(id -g)" \
  -e APP_ENV=test \
  -e DATABASE_URL="postgresql://app:app@db:5432/valeur_verte?serverVersion=16&charset=utf8" \
  --entrypoint "" app  vendor/bin/phpunit
```

En environnement `test`, Doctrine **ajoute le suffixe `_test`** (`dbname_suffix`) :
l'URL pointe donc toujours la base `valeur_verte`, jamais `valeur_verte_test` en dur.
Remplacer la dernière commande par `vendor/bin/phpstan analyse`,
`vendor/bin/php-cs-fixer fix --dry-run --diff` ou `php bin/console …` selon la porte.
La base de test doit être migrée une fois **par worktree** (chacun a la sienne) :
`scripts/app-dev.sh php bin/console doctrine:migrations:migrate --no-interaction`.

**Lancer un seul test** : `vendor/bin/phpunit --filter testNomDuTest`
(ou `vendor/bin/phpunit tests/BackOffice/Rapport/RapportTest.php`).

### Stack locale (app tournante pour vérification manuelle / Playwright)

Le `Makefile` porte une stack Docker (PostGIS partagé + app FrankenPHP) — voir la section
« Stack locale » du fichier :

```bash
make stack-info    # où tourne la stack de CE worktree (projet compose, ports)
make stack-up      # build + démarre db & app (image prod), migrations → http://127.0.0.1:$APP_PORT
make stack-dev     # itération : code app/ monté (pas de rebuild), APP_ENV=dev, sous-domaines
make stack-migrate # rejoue les migrations sur la base en cours
make stack-seed    # insère quelques rapports de démo (dont un lead)
make stack-logs    # suit les logs de l'app
make stack-down    # arrête (conserve les données)
make stack-reset   # arrête ET efface la base (repart de zéro)
make stack-purge   # efface TOUT (conteneurs, réseau, volumes, image) — avant « git worktree remove »
```

**La stack appartient au worktree, pas au dépôt.** Chaque worktree a son projet compose,
donc ses conteneurs, son réseau, ses volumes et ses ports — `make stack-info` les donne,
et le port n'est **pas** 8000 hors du worktree principal. Commencer par `stack-info` avant
de pointer un navigateur ou Playwright sur la stack : viser 8000 depuis un worktree lié,
c'est regarder l'application d'une autre tâche. En contrepartie, chaque worktree part
d'une **base vide** qu'il faut migrer (`make stack-migrate`) : la base porteuse du dump de
prod est celle du worktree principal, et elle n'est pas partagée.

`stack-up` exerce l'artefact de prod (le même qu'au VPS). `stack-dev` monte `app/` en
bind-mount et bascule en `APP_ENV=dev` : les changements de code sont pris sans
reconstruire l'image. Les zones vivent sur des **sous-domaines** de `%app.domaine%`
(défaut `valeurverte.localhost`, résolu vers 127.0.0.1 par les navigateurs) :
`app.` (espace pro), `admin.` (admin interne), `widget.` (widget public).

Vérifier une page, c'est vérifier qu'elle **fonctionne** (contenu, action attendue),
pas seulement qu'elle répond 200.

**Playwright — toujours tester les formulaires, pas seulement le rendu GET.** Vérifier
une page qui porte un formulaire, c'est le **soumettre** (remplir les champs, cliquer,
constater le résultat : redirection/mise à jour côté valide, message d'erreur/422 côté
invalide), pas seulement charger la page en GET. Une saisie valide **et** une saisie
invalide doivent être exercées. Un rendu GET vérifié seul n'est pas une vérification du
formulaire.

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
- Un rapport est **reproductible à l'identique** via son instantané JSONB — la
  reproductibilité porte sur les **calculs** (chiffres, contexte, barèmes), jamais sur
  les **données personnelles** : celles-ci sont soumises à la rétention (décision D10,
  [#68](../../../issues/68)) — contact effacé et adresse bornée à la commune à 24 mois,
  suppression du rapport à 3 ans. Un rapport anonymisé rend les mêmes chiffres ; il ne
  rend plus l'adresse ni le contact, et c'est voulu.
- Coefficients **pré-calculés par maille**, jamais à la volée.

---

## 8. Attendu de Claude

- Suivre le cycle **Red → Green → Refactor** avec **un commit par étape**, sans exception.
- **Un worktree dédié + une branche par issue** (créés depuis `main`, §2), PR vers `main`,
  statut de l'issue tenu à jour ; **supprimer le worktree une fois la tâche terminée**.
- **Avant chaque PR** : `git fetch origin main && git rebase origin/main`, rejouer les
  portes, ne pousser que sur une base à jour (§2).
- Lancer `make check` avant de déclarer une étape terminée, `make mutation` avant la revue ;
  rapporter honnêtement l'état (tests rouges = le dire).
- Ne pas affaiblir les portes qualité ni contourner un outil pour « faire vert ».
- Rester dans le périmètre de l'issue courante ; hors périmètre → nouvelle issue.
- **Lancer tout traitement long sur le VPS** (§5), jamais à travers le tunnel SSH, et
  indiquer comment en suivre l'avancement depuis une autre session.
- **Toute modification d'une règle de calcul du moteur → mise à jour de
  [`docs/MOTEUR-REGLES-CALCUL.md`](docs/MOTEUR-REGLES-CALCUL.md) dans le même commit**
  (voir « Règles de calcul du moteur »). Sans ça, la contribution est incomplète.
