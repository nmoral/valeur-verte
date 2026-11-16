# Valeur verte

« Valeur verte » en marque blanche : un outil que le professionnel (artisan RGE,
diagnostiqueur, mandataire MaPrimeRénov') utilise en rendez-vous (mode audit) ou partage
sous forme de **page publique hébergée à sa marque**, pour transformer un devis de
rénovation en **valeur patrimoniale mesurée**, à partir de transactions réelles (DVF)
croisées aux DPE, aux coûts de travaux et aux aides.

Le widget embarqué en iframe chez le pro est abandonné au profit de cette page hébergée
(décision **D9**) : voir [`docs/PAGE-PUBLIQUE.md`](docs/PAGE-PUBLIQUE.md).

Le cadrage complet est dans le document MVP de référence. Ce dépôt suit le développement
via un **backlog structuré en issues GitHub**.

## Démarrage local

**Prérequis sur le poste** : Docker + Docker Compose v2, GNU Make, Bash et Python ≥ 3.11
(pour `data/` et `infra/`). **PHP et Composer ne sont pas requis sur l'hôte** : tout
`app/` passe par l'image Docker (voir [`CLAUDE.md`](CLAUDE.md) §4).

```bash
make stack-up     # construit l'image app, démarre PostGIS, joue les migrations
make install      # dépendances data/ + infra/ (venv Python)
```

L'application est servie sur des **sous-domaines** de `valeurverte.localhost` (résolus
vers 127.0.0.1 par les navigateurs), **pas** sur `127.0.0.1` nu — ce dernier renvoie 404,
c'est normal :

- espace pro : `http://app.valeurverte.localhost:$APP_PORT` (redirige vers `/login`) ;
- admin interne : `http://admin.valeurverte.localhost:$APP_PORT` ;
- widget public : `http://widget.valeurverte.localhost:$APP_PORT`.

`make stack-info` affiche le port (`$APP_PORT`) et le nom de projet de la stack du
worktree courant. Hors worktree (clone simple), la stack retombe sur les ports **8000**
(app) et **5432** (db) : si l'un est déjà pris, surcharger `APP_PORT` / `POSTGRES_PORT`
avant `make stack-up`.

Portes qualité, cycle TDD et workflow worktree : [`CLAUDE.md`](CLAUDE.md).

## Suivi du projet : comment lire les issues

Tout le backlog vit dans les **[issues](../../issues)**. Il n'y a pas de milestones :
les jalons sont portés par des labels, pour que tout reste **filtrable et regroupable**.

### Hiérarchie

- Les **epics** (`type: epic`) sont les grands chantiers. Chacun liste ses tâches en
  **sous-issues** natives GitHub (visibles dans l'onglet de l'epic).
- Les **tâches** (`type: tâche`) sont les unités de travail actionnables.
- Les **décisions** (`type: décision`) se ferment par une décision documentée, pas par du code.

Voir l'index complet dans [`docs/BACKLOG.md`](docs/BACKLOG.md).

### Familles de labels

| Famille | Valeurs | Usage |
|---|---|---|
| `statut:` | à faire · en cours · bloqué · en revue · fini | **État kanban** de chaque issue |
| `type:` | epic · tâche · décision · chore | Nature de l'issue |
| `domaine:` | données · moteur · calcul-éco · mode-audit · back-office · widget · admin-interne · pdf · infra · rgpd · juridique · produit | **Regroupement fonctionnel** |
| `jalon:` | J1 → J6 · transverse · v2 | Étape de la feuille de route (§11 du cadrage) |
| `priorité:` | haute · moyenne · basse | Ordre de traitement |

### Exemples de filtres

- Tout ce qui reste à faire sur le moteur :
  `is:issue is:open label:"domaine: moteur" label:"statut: à faire"`
- Le contenu du jalon J3 (mode audit) :
  `is:issue label:"jalon: J3 — Mode audit"`
- Les décisions ouvertes :
  `is:issue is:open label:"type: décision"`
- Les blocages : `is:issue is:open label:"statut: bloqué"`

### Faire vivre les statuts

Chaque issue démarre en `statut: à faire`. Au fil du travail, remplacer par
`statut: en cours`, puis `statut: en revue` / `statut: bloqué` selon le cas, et enfin
`statut: fini` en **fermant** l'issue. On peut aussi créer un **Project** GitHub (vue
Board) groupé par le label `statut:` — les issues sont déjà prêtes pour ça.

## Couleurs des labels

Les labels ont été créés automatiquement (couleur par défaut). Pour appliquer la palette
définie dans [`.github/labels.yml`](.github/labels.yml), lancer une fois le workflow
**« Sync labels »** (onglet *Actions* → *Run workflow*). Il se relancera ensuite
automatiquement à chaque modification du fichier sur `main`.

## Architecture cible (rappel)

Deux projets dans un monorepo (voir §10 du cadrage) :

| Dossier | Techno | Rôle |
|---|---|---|
| `data/` | Python | Ingestion DVF/DPE/BAN, régressions, écriture des coefficients (schéma `data`) |
| `app/` | Symfony | Widget public, mode audit, back-office pro, admin interne (schéma `app`) |

Contrat : `data` est en **lecture seule** pour Symfony ; chaque projet gère ses migrations.

## Licence

Ce dépôt est publié en **source ouverte non commerciale**, sous la licence
[PolyForm Noncommercial 1.0.0](LICENSE) — © Nicolas Moral.

- **Usage non commercial autorisé** : étude, recherche, expérimentation, contributions,
  usage personnel, associatif, éducatif, par un organisme public ou de protection de
  l'environnement. Vous pouvez lire, exécuter, modifier et redistribuer le code dans ce
  cadre, à condition de conserver la licence et la mention de copyright.
- **Usage commercial interdit sans accord écrit préalable.** Toute exploitation
  commerciale (vente, SaaS, prestation facturée, intégration dans un produit payant,
  usage au sein d'une entreprise à but lucratif…) nécessite une **licence commerciale
  distincte**, négociée avec le titulaire des droits. Aucune autorisation n'est réputée
  accordée sans accord écrit explicite.

**Demande de licence commerciale** : ouvrir une issue intitulée « Demande de licence
commerciale » sur <https://github.com/nmoral/valeur-verte/issues>.
