# Protocole de test REG-1 sur le VPS — écarts entre millésimes (#129)

Valider REG-1 (rapport d'écarts entre millésimes, blocage > 25 % par maille) **sur
données réelles**, sur **un département** d'abord, avant d'étendre. On observe la
**vraie dérive** 2023 → 2024 : c'est le meilleur moyen de juger si les seuils
(10 % / 25 %) sont réalistes et de repérer les mailles qui bougent anormalement.

> Traitements longs = **conteneur détaché sur le VPS** (CLAUDE.md §5), jamais à
> travers le tunnel SSH. Le tunnel read-only reste bon pour l'inspection psql.

## Principe

1. Ingérer **2023**, calculer tout le département → l'historique s'amorce (aucun
   écart, pas de millésime antérieur).
2. Ingérer **2024**, calculer tout → REG-1 compare chaque maille à son 2023.
3. Lire le **rapport d'écarts CSV** (`--rapport-ecarts`) + le résumé + la base.

Département de référence proposé : **09 (Ariège)** — petit, rural, il exerce la
cascade LOCAL → EPCI → DÉPARTEMENT et les bandes. Remplaçable par tout autre code.

## Phase 0 — mise à jour du VPS

```bash
ssh vps 'cd /opt/valeur-verte && git pull && docker compose build migrate'
# Migrations (dont 010 : crée data.coefficient_maille_historique)
ssh vps 'cd /opt/valeur-verte && docker compose run --rm migrate valeur_verte_data.db.migrate'
# Dossier hôte pour récupérer les rapports CSV hors conteneur
ssh vps 'mkdir -p /opt/vv/rapports'
```

## Phase 1 — millésime 2023 (baseline)

```bash
# Ingestion (détachée, nommée, non auto-supprimée pour garder logs + code de sortie)
ssh vps 'cd /opt/valeur-verte && docker compose run -d --name ingest-09-2023 \
  migrate valeur_verte_data.cli ingest --departement 09 --millesime 2023'
ssh vps 'docker logs -f ingest-09-2023'          # suivre
ssh vps 'docker inspect -f "{{.State.Status}} {{.State.ExitCode}}" ingest-09-2023'

# Calcul — premier passage : amorce l'historique 2023, aucun écart attendu
ssh vps 'cd /opt/valeur-verte && docker compose run -d --name compute-09-2023 \
  -v /opt/vv/rapports:/rapports migrate valeur_verte_data.cli \
  compute --departement 09 --millesime 2023 --rapport-ecarts /rapports/ecarts-09-2023.csv'
ssh vps 'docker logs -f compute-09-2023'
```

Attendu : `compute-09-2023` publie N mailles, **0 blocage / 0 alerte** (pas de
précédent). `ecarts-09-2023.csv` ne contient que l'en-tête.

## Phase 2 — millésime 2024 (comparaison)

```bash
ssh vps 'cd /opt/valeur-verte && docker compose run -d --name ingest-09-2024 \
  migrate valeur_verte_data.cli ingest --departement 09 --millesime 2024'
ssh vps 'docker logs -f ingest-09-2024'

ssh vps 'cd /opt/valeur-verte && docker compose run -d --name compute-09-2024 \
  -v /opt/vv/rapports:/rapports migrate valeur_verte_data.cli \
  compute --departement 09 --millesime 2024 --rapport-ecarts /rapports/ecarts-09-2024.csv'
ssh vps 'docker logs -f compute-09-2024'
```

Le résumé de fin (`resume()`) annonce désormais `N publication(s) bloquée(s)
(REG-1), M alerte(s) d'écart`. Une ligne WARNING est émise **par maille bloquée**
(code INSEE, écart %, coefficient en cause).

## Phase 3 — lecture et jugement

Récupérer le CSV et l'analyser (localement, ou via psql pour croiser la base) :

```bash
scp vps:/opt/vv/rapports/ecarts-09-2024.csv .
```

Le CSV = une ligne par maille comparée : `code_insee,classe,ecart_pct,coefficient`.
Grille de lecture :

- **Distribution** : quelle proportion en normal / alerte / blocage ? Un pic de
  blocages (> quelques %) sur une dérive annuelle **normale** signale un seuil trop
  serré (ou un vrai problème de données 2024) — à investiguer avant de généraliser.
- **Coefficient en cause** : les blocages sont-ils portés par le DPE (signal métier)
  ou par une élasticité `bâti`/`terrain` (souvent un artefact d'échantillon) ?
- **Cas extrêmes** : `ecart_pct = inf` = une classe DPE apparue depuis zéro (souvent
  un rebanding entre millésimes) — attendu, à vérifier au cas par cas.
- **Cohérence géographique** : les mailles bloquées sont-elles éparses (bruit) ou
  groupées (un secteur où le marché a réellement bougé) ?

### Inspection base (tunnel read-only)

```bash
ssh -f -N -L 15432:127.0.0.1:5432 vps
```

```sql
-- Mailles bloquées : leur ligne vive est restée au millésime précédent
SELECT v.code_insee, v.millesime AS millesime_vif
FROM data.coefficient_maille v
JOIN (SELECT code_insee, max(millesime) m FROM data.coefficient_maille_historique GROUP BY 1) h
  USING (code_insee)
WHERE v.millesime < h.m;               -- = non republiées par REG-1

-- L'historique contient bien les deux millésimes
SELECT millesime, count(*) FROM data.coefficient_maille_historique GROUP BY 1 ORDER BY 1;

-- Répartition par cran de cascade (non-régression #169)
SELECT mode, palier, count(*) FROM data.coefficient_maille GROUP BY 1, 2 ORDER BY 1, 2;
```

Vérifs clés :
- une maille **bloquée** a sa **valeur vive au millésime 2023** (l'ancienne est
  conservée) mais figure en **2024** dans l'historique (calculée, archivée, non publiée) ;
- les colonnes `mode` / `palier` / `bandes` restent peuplées (REG-1 n'a rien cassé
  de la cascade #169) ;
- rejouer `compute --millesime 2024` redonne **exactement** le même rapport
  (déterminisme INV-5) et ne republie rien à tort.

## Phase 4 — extension

Une fois le 09 jugé sain, rejouer Phases 1–3 sur d'autres départements (Occitanie
puis Grand Est rural, cf. `resultats-couverture-rurale.md`), en comparant les
distributions d'écarts entre territoires.

## Nettoyage

```bash
ssh vps 'docker rm ingest-09-2023 compute-09-2023 ingest-09-2024 compute-09-2024'
```

## Limites connues

- **Amplitude de la dérive non maîtrisée** : on observe la vraie dérive 2023→2024,
  qui peut produire peu de blocages. Pour valider *le mécanisme* de blocage de façon
  déterministe (forcer un blocage/alerte connu), un second protocole seede une ligne
  d'historique `M-1` volontairement décalée pour quelques communes témoins — à faire
  seulement si la dérive réelle ne déclenche aucun blocage.
- Le rapport complet n'existe qu'en **CSV** (`--rapport-ecarts`) et dans les logs ;
  l'écran de supervision (#45) et la validation manuelle (REG-2, #130) sont hors
  périmètre de cette PR.
