---
name: vv-init-env-test
description: >-
  Initialise l'environnement de test PHP de `app/` (projet Valeur Verte) dans un
  worktree, de zéro jusqu'à une suite PHPUnit verte : image de la stack, vendor/
  avec les dev-deps, base de test `valeur_verte_test` migrée avec tout le schéma,
  schéma `data` provisionné, cache purgé, mémoire suffisante. À utiliser DÈS qu'on
  veut faire tourner les tests app d'un worktree — « prépare l'environnement de
  test », « initialise la base de test », « lance les tests de app », « make test
  échoue », « pourquoi j'ai plein d'erreurs relation X does not exist / Class
  PhpDocParser not found / memory exhausted en lançant phpunit », « je veux jouer
  la mutation testing » — même sans dire « environnement » ou « skill », et en
  particulier sur un worktree fraîchement créé où rien n'est encore installé.
---

# Initialiser l'environnement de test — Valeur Verte

Sur ce dépôt, le poste n'a pas de PHP 8.4 : **toutes les portes `app/` se jouent
dans l'image de la stack** via `scripts/app-dev.sh` (voir `CLAUDE.md` §4). Un
worktree neuf ne contient ni `vendor/`, ni base de test migrée, ni schéma `data` —
et plusieurs pièges non écrits font échouer la suite en masse si on ne les traite
pas dans le bon ordre. Ce skill encode le chemin « de zéro à suite verte » et la
porte de mutation, en un script idempotent chacun.

## Contexte à connaître (sinon on se trompe de base)

- **La racine `valeur-verte-root/` n'est PAS un dépôt git.** Le dépôt est
  `valeur-verte/` (worktree principal) ; les worktrees liés sont des dossiers
  frères `valeur-verte-issue-<num>/`. **Toutes les commandes ci-dessous se lancent
  depuis la racine d'un worktree** (là où vivent `Makefile` et `scripts/app-dev.sh`).
- **Un jeu de conteneurs et une base PAR worktree** (projet compose `vv-issue-<num>`,
  ports dérivés par `scripts/worktree-env.sh`). `scripts/app-dev.sh` et le `Makefile`
  ciblent automatiquement la stack du worktree courant ; un `docker compose` lancé
  **sans `-p`** retomberait sur le projet `infra` (worktree principal) et taperait la
  mauvaise base.

## Chemin nominal — une seule commande

Depuis la racine du worktree :

```bash
.claude/skills/vv-init-env-test/scripts/init-test-env.sh --smoke
```

Le script est **idempotent** (sûr à rejouer) et enchaîne, dans l'ordre :

1. **`make stack-up`** — construit l'image applicative (cache Docker ⇒ rapide) et
   démarre `db` + `app` du worktree. On a besoin de l'image (pour y jouer PHP) et de
   la base.
2. **`vendor/` avec les dev-deps** — si `app/vendor/bin/phpunit` manque, `scripts/app-dev.sh
   composer install`. *Voir piège 1.*
3. **Migration de la base de test** `valeur_verte_test`. *Voir piège 2.*
4. **Purge de `app/var/cache/test`**. *Voir piège 3.*
5. `--smoke` : joue `tests/Publication` comme témoin (il provisionne le schéma `data`,
   donc valide aussi le montage `data/`). `--full` : joue toute la suite. Sans argument :
   prépare seulement et affiche les commandes.

Ensuite, lancer la suite se fait toujours ainsi (**`memory_limit=-1` obligatoire**,
*piège 4*) :

```bash
scripts/app-dev.sh php -d memory_limit=-1 vendor/bin/phpunit
```

## Les quatre pièges (déjà payés — c'est ce que le script neutralise)

1. **L'image prod n'embarque AUCUN dev-dep** (phpunit/phpstan/cs-fixer) : elle est
   construite `--no-dev`. Un `docker cp <app>:/app/vendor app/vendor` donne donc un
   vendor **sans** outils de test. Il faut peupler un vendor local complet via
   `scripts/app-dev.sh composer install` (composer + PHP 8.4 sont dans l'image).
2. **`make stack-up` ne migre que la base DEV** (`valeur_verte`), pas
   `valeur_verte_test`. Sans migration dédiée, la suite part sur des **centaines
   d'erreurs `relation "utilisateur"/"tenant"/… does not exist`**. `app-dev.sh`
   tourne en `APP_ENV=test` ⇒ Doctrine ajoute le suffixe `_test` tout seul :
   `scripts/app-dev.sh php bin/console doctrine:migrations:migrate --no-interaction`.
3. **Cache de test compilé périmé** après un changement de `vendor/` (ou de gabarit
   Twig) : le conteneur/templates compilés dans `var/cache/test` jettent des erreurs
   trompeuses au rendu, typiquement `Class "PHPStan\PhpDocParser\Lexer\Lexer" not
   found` dans les gabarits admin/back-office. Remède : `rm -rf app/var/cache/test`.
4. **`vendor/bin/phpunit` nu OOM à 128 Mo** dans `mpdf/…/TTFontFile.php` (tests de
   génération PDF, vers 88 % de la suite). Lancer la suite **complète** avec
   `php -d memory_limit=-1`.

## Le schéma `data` n'est pas à créer à la main

`data.commune`, `data.coefficient_maille`, `data.publication_millesime`… ne sont
**pas** provisionnés par ce skill. Les tests qui en dépendent (`tests/Publication`,
`tests/AdminInterne/Millesime`, …) le font eux-mêmes en rejouant les migrations du
pipeline via le trait `App\Tests\Publication\ProvisionneModelePublie` — **à condition
que `data/` soit monté** (`VV_DATA_DIR`), ce que `app-dev.sh` fait déjà. Si ces tests
échouent sur « La migration 012 doit être lisible depuis les tests », c'est un montage
`data/` manquant, pas un retard de schéma.

## Vérifier que c'est vert

Le récapitulatif attendu de la suite complète est `OK` (p.ex. `Tests: 1727, OK`).
**Les lignes `[error]`/`[critical]` dans la sortie sont du bruit** : ce sont des
exceptions **attendues** loguées par des tests qui asservissent des réponses 404/429
(routes inexistantes, rate-limits). Seule compte la ligne de synthèse finale
(`OK` vs `ERRORS!`/`FAILURES!`) et le nombre de `Errors`/`Failures`. Viser **zéro**.

## Mutation testing (porte avant revue)

`make mutation` suppose PHP + pcov locaux. Sans PHP local, jouer Infection sur un
périmètre dans l'image :

```bash
.claude/skills/vv-init-env-test/scripts/run-mutation.sh \
  src/Publication/IndexabiliteCommune.php,src/Publication/FicheCommune.php tests/Publication
```

- 1er argument : les fichiers/dossiers de `app/src` à muter (séparés par des virgules).
- 2e argument (optionnel) : un dossier de tests pour **restreindre la couverture** et
  aller vite — à n'utiliser que si tous les tests couvrant le périmètre y vivent,
  sinon des mutants passeront pour « non couverts ». À défaut : suite entière.

Le script fait tout dans **un seul conteneur root** (pcov installé à la volée →
couverture `memory_limit=-1` → Infection filtré `--only-covering-test-cases` →
`chown` de retour de `var/`+`.phpunit.cache`). Cible : **MSI couvert ≥ 80 %** (`CLAUDE.md`
§3). Un run réussi affiche `Covered Code MSI`. Détails et pièges historiques
(timeout Infection flatteur, réseau/DB du worktree, vendor hardlinké partagé) : voir
`references/pieges-mutation.md`.

## Portée du skill

Ce skill prépare et **exécute** les tests/mutation. Il ne monte pas l'app pour une
vérification manuelle (ça, c'est `make stack-up` + navigateur, `CLAUDE.md` §4) et ne
touche pas au pipeline `data/` ni à `infra/` (venvs Python, `make install`).
