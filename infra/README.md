# Déploiement (`infra/`)

Déploiement du pipeline `data` sur le VPS OVH — issue [#78](../../../issues/78).
`app/` n'a pas encore de code : le service web viendra dans le même compose (étape C).

## Ce qui tourne où

| Élément | Emplacement |
|---|---|
| Base PostGIS + tâche de migration | VPS, `/opt/valeur-verte` |
| Image du pipeline | `ghcr.io/nmoral/valeur-verte-data:<sha du commit>` |
| Identifiants | GitHub → environnement `vps` |
| Orchestration | [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) |

## Déroulé d'un déploiement

Déclenché par un push sur `main` (ou manuellement via *Actions → Déploiement*) :

1. **`image`** — construit `data/Dockerfile` et publie sur GHCR, tag = SHA du commit.
2. **`deploy`** — dépose `docker-compose.prod.yml` et un `.env` (mode 600) dans
   `/opt/valeur-verte`, authentifie le VPS à GHCR avec le jeton éphémère du job,
   tire l'image, démarre la base puis applique les migrations.

Les migrations sont **idempotentes** : elles sont rejouées à chaque livraison, un
rejeu sans migration en attente ne fait rien.

## Préparation du VPS (une seule fois)

[`provision-vps.sh`](provision-vps.sh) est **la** procédure de reconstruction de
l'hôte. Elle est idempotente : rejouable sans dommage, et pensée pour qu'une
machine dérivée soit refaite plutôt que rafistolée.

```bash
scp infra/provision-vps.sh vps:/tmp/
ssh vps 'sudo bash /tmp/provision-vps.sh'
```

Elle refuse d'emblée une version d'Ubuntu non-LTS — le garde-fou qui empêche de
reconstituer la situation de [#99](../../../issues/99) — puis installe les
correctifs automatiques, Docker depuis le dépôt officiel, ajoute le compte au
groupe `docker`, crée `/opt/valeur-verte` et n'autorise plus que
l'authentification par clé en SSH.

Deux prérequis restent manuels : la clé publique de déploiement dans
`~/.ssh/authorized_keys` du compte, et le choix de cette même clé lors d'une
réinstallation depuis le manager OVH.

`/opt` appartenant à `root`, le compte de déploiement ne peut pas créer son
répertoire lui-même : le workflow vérifie qu'il est inscriptible et s'arrête sur
un message explicite sinon, plutôt que d'échouer sur un « Permission denied » de
`scp` dont la cause serait difficile à relier.

## Secrets attendus (environnement `vps`)

| Secret | Rôle |
|---|---|
| `SSH_HOST` | Hôte du VPS |
| `SSH_USER` | Compte de déploiement (doit être dans le groupe `docker`) |
| `SSH_KEY` | Clé privée de déploiement, dont la publique est dans `authorized_keys` |
| `POSTGRES_PASSWORD` | Mot de passe du rôle `valeur_verte` |

`POSTGRES_USER`, `POSTGRES_DB` et `DATABASE_URL` ne sont pas des secrets : le
workflow les compose.

> **Contrainte sur le mot de passe** : il ne doit contenir ni apostrophe (`'`)
> ni barre oblique inverse (`\`). Ni le format `.env` de Compose ni le format
> conninfo de libpq ne les transportent sans ambiguïté. `infra/ecrire-env.sh`
> refuse le déploiement plutôt que de livrer un identifiant tronqué — panne qui
> ne se manifesterait que bien plus tard, sur une base déjà initialisée. Tous
> les autres caractères (`$`, espaces, `"`, `` ` ``, `&`, `|`, `;`…) passent, et
> sont couverts par les tests.

Le compose de production **n'a aucune valeur de repli** : une variable manquante
fait échouer le déploiement plutôt que démarrer la base avec un identifiant de
développement.

## Exploitation

La base est publiée **sur la boucle locale du VPS uniquement**
(`127.0.0.1:5432`) : injoignable depuis Internet, atteignable par un tunnel SSH.
Le test `test_la_base_n_est_jamais_publiee_sur_une_interface_publique` rejette
toute publication sans adresse, qui écouterait sur `0.0.0.0`.

Session `psql` directement sur le serveur :

```bash
ssh vps 'cd /opt/valeur-verte && docker compose exec db psql -U valeur_verte -d valeur_verte'
```

Client local (PyCharm, DBeaver, `psql`) — ouvrir un tunnel, puis s'y connecter :

```bash
ssh -f -N -L 15432:127.0.0.1:5432 vps      # tunnel en arrière-plan
psql "host=127.0.0.1 port=15432 dbname=valeur_verte user=valeur_verte"
```

Le port local est décalé à `15432` pour ne pas heurter une instance PostgreSQL
locale. Le tunnel meurt avec la session SSH ; le relancer au besoin, ou ajouter
`LocalForward 15432 127.0.0.1:5432` à l'entrée `Host vps` de `~/.ssh/config`.

Le mot de passe est celui du secret `POSTGRES_PASSWORD` ; il se lit aussi dans
`/opt/valeur-verte/.env` sur le serveur.

**Revenir en arrière** — l'image est taguée par le SHA, donc :

```bash
cd /opt/valeur-verte
sed -i 's/^DATA_IMAGE_TAG=.*/DATA_IMAGE_TAG=<sha précédent>/' .env
docker compose pull && docker compose up -d db
```

Les migrations, elles, ne se rejouent pas à l'envers : un retour arrière de
schéma demande une migration correctrice.

## Développement local

```bash
docker compose -f infra/docker-compose.yml up -d db   # PostGIS de développement
make infra-test                                       # invariants + image
```

Les tests d'intégration construisent réellement l'image et l'exercent contre la
base ; ils se skippent d'eux-mêmes sans Docker ou sans `DATABASE_URL`.

## Limites connues

- **Empreinte de l'hôte SSH** : le workflow fait un `ssh-keyscan` (confiance à la
  première connexion). Épingler l'empreinte dans un secret `SSH_KNOWN_HOSTS`
  fermerait la fenêtre d'usurpation.
- **Environnement `vps` non protégé** : aucune politique de branche ne le
  restreint, donc un workflow lancé depuis une autre branche pourrait lire
  `SSH_KEY`. Le déclencheur du workflow est limité à `main`, mais une protection
  d'environnement serait la défense en profondeur.
- **Sauvegardes** : non couvertes ici, voir [#55](../../../issues/55).
- **Ingestion planifiée** : le déploiement n'applique que les migrations ; les
  CLI `ingest` / `compute` par département arrivent avec
  [#91](../../../issues/91), puis seront ordonnancées (étape B de #78).
