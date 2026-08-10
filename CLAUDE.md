# CLAUDE.md — Règles de travail (Valeur verte)

Ce fichier fait autorité pour toute contribution au dépôt, humaine ou assistée.
Contexte produit : voir [`README.md`](README.md) ; backlog : [`docs/BACKLOG.md`](docs/BACKLOG.md).

## Structure du dépôt (monorepo)

| Dossier | Techno | Rôle |
|---|---|---|
| `app/` | PHP 8.3 / Symfony 7 | Widget public, mode audit, back-office pro, admin interne (schéma `app`) |
| `data/` | Python 3.11+ | Ingestion DVF/DPE/BAN, régressions, écriture des coefficients (schéma `data`) |

Contrat entre les deux : `data` est **lecture seule** pour `app` ; chacun gère ses propres migrations.

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

---

## 5. Amorçage des sous-projets (une fois)

Le harnais qualité (`Makefile` + fichiers de config) est en place. Pour matérialiser chaque
sous-projet la première fois :

- **`app/`** : `cd app && composer install` (le squelette Symfony s'ajoute via
  `composer require symfony/framework-bundle …` ou `symfony new`). Les configs
  `phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `phpunit.dist.xml`, `infection.json5` sont fournies.
- **`data/`** : `cd data && python -m venv .venv && . .venv/bin/activate && pip install -e '.[dev]'`.
  Toute la config outillage est dans `pyproject.toml`.

---

## 6. Rappels produit (à ne jamais casser)

Ces invariants viennent du cadrage et sont vérifiés par des tests :

- Le **coefficient statistique n'est jamais modifiable** ; l'ajustement du pro est une **ligne
  séparée motivée**, en mode audit uniquement.
- Toute restitution affiche **n, intervalle de confiance, rayon, millésime**.
- **Aucun `UPDATE` destructif** sur les barèmes/grilles : versionnement `valide_du`/`valide_au`.
- Un rapport est **reproductible à l'identique** via son instantané JSONB.
- Coefficients **pré-calculés par maille**, jamais à la volée.

---

## 7. Attendu de Claude

- Suivre le cycle **Red → Green → Refactor** avec **un commit par étape**, sans exception.
- **Une branche par issue**, PR vers `main`, statut de l'issue tenu à jour.
- Lancer `make check` avant de déclarer une étape terminée, `make mutation` avant la revue ;
  rapporter honnêtement l'état (tests rouges = le dire).
- Ne pas affaiblir les portes qualité ni contourner un outil pour « faire vert ».
- Rester dans le périmètre de l'issue courante ; hors périmètre → nouvelle issue.
