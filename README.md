# Valeur verte

Widget « Valeur verte » en marque blanche : un outil que le professionnel (artisan RGE,
diagnostiqueur, mandataire MaPrimeRénov') intègre à son site ou utilise en rendez-vous
pour transformer un devis de rénovation en **valeur patrimoniale mesurée**, à partir de
transactions réelles (DVF) croisées aux DPE, aux coûts de travaux et aux aides.

Le cadrage complet est dans le document MVP de référence. Ce dépôt suit le développement
via un **backlog structuré en issues GitHub**.

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
