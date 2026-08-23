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

### Santé applicative ([#313](../../../issues/313))

Le conteneur `app` ne se contente plus de dire que son serveur web est debout : sa
sonde interroge la route `/sante`, qui vérifie **ce qui tue le produit** — la base
répond, les migrations sont toutes jouées, `var/` est inscriptible. Elle rend `200`
si tout va bien, `503` sinon, avec le détail par contrôle :

```bash
ssh vps 'cd /opt/valeur-verte && docker compose exec app curl -s http://127.0.0.1:80/sante'
# {"statut":"ok","controles":{"base":"ok","ecriture":"ok","migrations":"ok"}}

# État vu par Docker, et le journal des derniers passages de la sonde :
ssh vps 'cd /opt/valeur-verte && docker compose ps app'
ssh vps 'cd /opt/valeur-verte && docker inspect -f "{{json .State.Health}}" $(docker compose ps -q app)'
```

Une base injoignable ou une migration manquante fait donc passer le conteneur en
`unhealthy` (30 s d'intervalle, 3 échecs, 30 s de grâce au démarrage) au lieu de le
laisser passer pour un service en bonne santé.

La route est **ouverte sans authentification** — la sonde n'a pas de session — et
reste donc **muette** : un `ok`/`ko` par contrôle, aucune version, aucune trace, et
le proxy retire même la signature du runtime (`Server`, `X-Powered-By`). Elle
traverse aussi le basic auth de la préversion et la bascule maintenance : une
surveillance externe doit pouvoir constater la panne pendant une intervention, et
**seule** une surveillance externe voit le VPS tomber en entier.

```bash
curl -si https://valeur-verte.fr/sante | head -1      # sans identifiants
```

### Tâches planifiées

Le service `cron` tourne l'image `app` avec [supercronic](https://github.com/aptible/supercronic)
et lit `infra/cron/crontab`, versionné dans le dépôt et téléversé à chaque
déploiement. Ajouter une tâche, c'est donc modifier ce fichier — jamais un
`crontab -e` sur le serveur, qui serait effacé par la première reconstruction de
l'hôte. Le registre des tâches vit dans l'issue [#190](../../../issues/190).

| Commande | Fréquence (UTC) | Rôle |
|---|---|---|
| `app:widget:purger-evenements` | 03:00 | Purge des événements de parcours de la page publique au-delà de 90 jours ([#90](../../../issues/90)) |
| `app:widget:purger-demandes-lead` | 03:10 | Purge des demandes de lead non confirmées ou expirées ([#292](../../../issues/292)) |
| `app:rapport:appliquer-retention` | 03:20 | Rétention D10 : anonymisation à 24 mois, suppression à 3 ans ([#294](../../../issues/294)) |
| `app:compte:effacer-exigibles` | 03:30 | Effacement définitif des comptes dont la demande de suppression a dépassé le délai de grâce de 30 jours ([#308](../../../issues/308)) |
| `app:sauvegarde:executer` | dim. 02:00 | Dump complet hebdomadaire de la base, élagage au-delà de 30 j ([#55](../../../issues/55)) |
| `app:essai:relancer` | — | Email de relance de fin d'essai ([#108](../../../issues/108)) — **ligne commentée, pas encore activée** : elle écrit à de vraies personnes, elle ne s'allume pas comme effet de bord de la mise en place de l'ordonnanceur |

Toutes sont **idempotentes** : les rejouer ne fait rien de plus. Elles sont
décalées de dix minutes pour ne pas balayer la base en même temps, et
supercronic refuse de lui-même de démarrer une exécution tant que la précédente
tourne — aucun verrou applicatif n'est nécessaire.

Suivre les exécutions, et en déclencher une à la main sans attendre son heure :

```bash
ssh vps 'cd /opt/valeur-verte && docker compose logs -f cron'
ssh vps 'cd /opt/valeur-verte && docker compose exec cron php bin/console app:rapport:appliquer-retention'
```

Vérifier ce que l'ordonnanceur a réellement chargé (il journalise chaque tâche et
sa prochaine échéance à son démarrage) :

```bash
ssh vps 'cd /opt/valeur-verte && docker compose logs cron | head -20'
```

**Revenir en arrière** — l'image est taguée par le SHA, donc :

```bash
cd /opt/valeur-verte
sed -i 's/^DATA_IMAGE_TAG=.*/DATA_IMAGE_TAG=<sha précédent>/' .env
docker compose pull && docker compose up -d db
```

Les migrations, elles, ne se rejouent pas à l'envers : un retour arrière de
schéma demande une migration correctrice.

## Sauvegarde et restauration ([#55](../../../issues/55), §9.11)

> « Une perte de base n'est pas un incident technique, c'est la fin du produit. »

**Ce qui est sauvegardé.** Un **dump complet** de la base (schémas `app` + `data` +
PostGIS) au format custom (`pg_dump -Fc`), **une fois par semaine** (dimanche 02:00
UTC), écrit par l'ordonnanceur dans le volume nommé `db_backups`. Le dump tourne sous
le rôle **superutilisateur** (`POSTGRES_USER`) : le rôle applicatif `app`, en lecture
seule sur `data`, ne peut pas dumper la base entière.

**Pourquoi un dump complet et pas seulement `app`.** Restaurer `app` sans `data`
laisserait une base que l'application ne peut pas lire (elle lit `data` en SELECT).
Le schéma `data` est reconstructible depuis les sources, mais le re-générer prend des
heures : le garder dans le dump ramène le produit en une passe.

**Rétention et disque.** Les dumps de plus de `VV_SAUVEGARDE_RETENTION_JOURS` jours
(30 par défaut) sont élagués à chaque exécution → environ **4 dumps de ~400 Mo**
conservés, soit ~1,6 Go. Ajuster la variable sur le service `cron` si le disque du VPS
est contraint. L'élagage ne touche **jamais** un fichier qu'il ne sait pas dater comme
un dump à nous, et un `pg_dump` qui échoue n'élague rien (on ne grignote pas la
dernière copie).

**Pas de copie hors-site** (choix assumé, faute d'hébergement second) : ces dumps
protègent d'une migration ratée ou d'un effacement, **pas d'une perte de l'hôte**. Le
jour où un stockage objet est disponible, y recopier `db_backups` est la suite
naturelle.

**Déclencher un dump à la main :**

```bash
ssh vps 'cd /opt/valeur-verte && docker compose exec cron php bin/console app:sauvegarde:executer'
ssh vps 'cd /opt/valeur-verte && docker volume ls | grep db_backups'
```

### Test de restauration réel

Un dump jamais restauré n'est pas une sauvegarde, c'est une hypothèse. À jouer
**avant la première mise en production, puis tous les six mois**. Le script
`infra/restauration-test.sh` restaure un dump dans une base **jetable**, y vérifie que
les tables clés portent des lignes, puis la supprime — il n'écrit jamais dans la base
de production.

```bash
# Sur le VPS : l'image app porte pg_restore/psql, monter le script et le volume.
ssh vps 'cd /opt/valeur-verte && source .env && docker compose -f infra/docker-compose.prod.yml \
  run --rm --no-deps \
  -v ./infra:/infra:ro -v db_backups:/sauvegardes:ro \
  -e PGHOST=db -e PGUSER="$POSTGRES_USER" -e PGPASSWORD="$POSTGRES_PASSWORD" -e PGDATABASE="$POSTGRES_DB" \
  --entrypoint bash cron /infra/restauration-test.sh /sauvegardes/valeur-verte-AAAAMMJJ-HHMMSS.dump'
```

Le script sort en erreur si la restauration échoue ou si `data.vente` est vide après
coup — un vert signifie que le dump est réellement restaurable.

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
- **Surveillance externe non branchée** : `/sante` est exposée pour elle
  ([#313](../../../issues/313)), mais aucun service tiers ne l'interroge encore —
  tant que personne ne la sonde de l'extérieur, une panne du VPS entier reste
  invisible jusqu'à ce qu'un humain regarde.
- **Sauvegardes** : non couvertes ici, voir [#55](../../../issues/55).
- **Ingestion planifiée** : le déploiement n'applique que les migrations ; les
  CLI `ingest` / `compute` par département arrivent avec
  [#91](../../../issues/91), puis seront ordonnancées (étape B de #78).
