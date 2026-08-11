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

## Secrets attendus (environnement `vps`)

| Secret | Rôle |
|---|---|
| `SSH_HOST` | Hôte du VPS |
| `SSH_USER` | Compte de déploiement (doit être dans le groupe `docker`) |
| `SSH_KEY` | Clé privée de déploiement, dont la publique est dans `authorized_keys` |
| `POSTGRES_PASSWORD` | Mot de passe du rôle `valeur_verte` |

`POSTGRES_USER`, `POSTGRES_DB` et `DATABASE_URL` ne sont pas des secrets : le
workflow les compose. Le compose de production **n'a aucune valeur de repli** —
une variable manquante fait échouer le déploiement plutôt que démarrer la base
avec un identifiant de développement.

## Exploitation

La base ne publie aucun port : elle n'est joignable que depuis le réseau interne
du compose. Pour une session `psql` :

```bash
ssh -L 5432:localhost:5432 <user>@<hôte>          # tunnel
docker compose -f /opt/valeur-verte/docker-compose.yml exec db \
  psql -U valeur_verte -d valeur_verte
```

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
