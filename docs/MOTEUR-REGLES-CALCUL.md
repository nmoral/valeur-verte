# Moteur — règles de calcul (référentiel)

Référentiel **exhaustif et à jour** des règles du moteur (`data/src/valeur_verte_data/`) :
filtres, modèle, pondération, seuils, invariants, sorties. Chargé en permanence via
`@` dans `CLAUDE.md`. **Toute modification du moteur doit mettre ce fichier à jour.**

> ⚠️ **Pièges à ne jamais oublier** (erreurs déjà commises) :
> 1. **Segment = maisons seulement** (`SEGMENT_MVP`). Les appartements sont **exclus**.
> 2. **Base prod SANS BORNES** (S12) : `data.vente` est brute (prix de 1 € à 16 M€,
>    surface jusqu'à 12 000 m²). Les bornes §7 du code (`cleaning.py`) ne sont PAS
>    reflétées dans les données. Le nettoyage des outliers se fait au fit (S32).
> 3. **Coefficients en LOG, pas en €** : `coef_dpe` = effets log, `coef_m2_*` = élasticités.
> 4. **Bâti récent inclus** (`restreindre_bati_ancien=false`, S20) — pas seulement le pré-1989.
> 5. **Effets fixes commune désactivés** (S22).

---

## 1. Chaîne de calcul (par commune)

```
pool département+limitrophes (maisons)          [pool_repository.load_pool]
  → sélection par rayon adaptatif (→ σ densité) [radius.adaptive_selection]
  → décision de mode LOCAL / DÉPT / REFUS        [degraded.decide_mode]
  → écrêtage des outliers sur résidu (S32)       [trimming.ecreter_residu]
  → filtre des classes DPE publiables            [batch: min_class_sample]
  → régression pondérée log-prix                 [regression.fit]
  → erreur médiane par validation croisée        [cross_validation]
  → invariants de publication (INV-1..4)         [invariants]
  → ligne coefficient_maille (ou refus)          [batch.compute_maille]
```
Orchestration département : `orchestration.compute_departement` (pool chargé une
fois, §10.6 ; une transaction par commune ; refus ⇒ `delete_coefficient`).

## 2. Segment de biens

- **Maisons uniquement** — `pool_repository.SEGMENT_MVP = "Maison"`, `config.segment = "Maison"`.
  `load_pool` filtre `type_local = 'Maison'`. Décision #115 : sur les appartements
  l'emplacement (étage, immeuble) écrase le DPE (effet de signe opposé). Les
  appartements ne sont **pas calculés**.

## 3. Données et filtres

**Ingestion** (`ingest.ingest_commune`) : DVF → nettoyage → appariement DPE/période → écriture.
- **Porte de schéma DAT-1** (#124, `schema_source.py`) : **au bord**, quand chaque
  source est lue (`dvf_source`, `dpe_source`, `decoupage_source`), les colonnes lues
  par le pipeline doivent être présentes. Une colonne renommée lève une
  `SchemaSourceError` **nommant la source et la colonne** (pas un `.get()`→`None`
  silencieux) ; l'erreur, systémique, **interrompt tout le département**
  (`ingest_departement` ne l'avale pas dans son `try/except` par-commune) — le calcul
  ne tourne pas sur des données muettes. Le schéma attendu et le vocabulaire
  `periode_construction` (signalé, non bloquant) sont **versionnés** dans
  `schema_source.py`. Voir [`QUALITE-DONNEES.md`](QUALITE-DONNEES.md) § DAT-1.
- **Assertion de volumétrie DAT-2** (#125, `volumetrie.py`, `dat2.py`) : **en fin
  d'ingestion, par département**, le nombre de ventes DVF insérées
  (`mutations_inserees`, déjà archivé par #131 dans `data.rapport_batch`) est
  confronté à celui du **millésime précédent** (relu, `lire_volumetrie_precedent` ;
  la volumétrie courante, elle, est en main). Un écart au-delà de
  `ecart_volumetrie_max_pct` (± 30 %, borne stricte) déclenche une **alerte
  journalisée** (`porte_dat2` câblée dans la commande CLI `ingest` : log + message),
  **jamais un échec ni un blocage** — DVF peut légitimement bouger, et c'est la
  **seule assertion DAT non bloquante** : le calcul reste autorisé, le code de sortie
  ne dépend que de l'ingestion. Sans base positive (premier chargement, ou précédent
  à zéro) le verdict est **neutre**. Voir [`QUALITE-DONNEES.md`](QUALITE-DONNEES.md)
  § DAT-2.
- **Mesure de distribution DAT-4** (#127, `vente_repository.calculer_distribution`) :
  **en fin d'ingestion**, les distributions observées du département (prix médian,
  surface bâtie médiane — `percentile_cont` sur `data.vente` jointe à `data.commune`
  par `code_departement`, robustes sur la base brute S12 —, nombre de ventes à terrain
  **nul ou hors bornes §7**, histogramme des étiquettes DPE) sont mesurées et
  **archivées** en JSONB dans `data.rapport_batch` (#131). Elles sont **relues au
  calcul** par la porte DAT-4 (§11) — la mesure vit à l'ingestion, la décision au
  calcul (comme DAT-3). Voir [`QUALITE-DONNEES.md`](QUALITE-DONNEES.md) § DAT-4.
- **Fraîcheur des sources DAT-5** (#128, `fraicheur.py`, `dat5.py`) : **en fin
  d'ingestion**, la date de mutation la plus récente du département
  (`vente_repository.date_mutation_max` sur `data.vente` ⋈ `data.commune`) est
  confrontée à la **date d'exécution** du batch (`evaluer_fraicheur`). Au-delà de
  `fraicheur_max_mois` (12 mois, borne stricte) d'ancienneté, une **alerte**
  journalisée est émise (`porte_dat5` câblée dans la commande CLI `ingest`), **jamais
  un blocage** — DVF est publié semestriellement, une source figée vieillit en silence
  ; un backfill volontaire alerte légitimement. La date est **archivée**
  (`rapport_batch.mutation_la_plus_recente`, migration 016) : source de vérité de la
  fraîcheur/millésime consommée par l'admin (#46/#48). Sans vente datée, le verdict est
  **neutre**. Voir [`QUALITE-DONNEES.md`](QUALITE-DONNEES.md) § DAT-5.
- `cleaning.clean_mutations` applique les bornes §7 **par défaut** : prix ∈ [30 000 ;
  900 000] €, bâti ∈ [50 ; 250] m², terrain ∈ [50 ; 5 000] m² (si présent).
- **Appariement DPE** (`dpe.build_dpe_lookup`, `attach_dpe`) : indexé par `(code_postal,
  clé d'adresse BAN)`. Quand une adresse porte plusieurs diagnostics, le **DPE le plus
  récent** (date d'établissement max) fait foi — étiquette, période, **date
  d'établissement** et **version de méthode** viennent tous du même diagnostic (#211).
  Un DPE daté prime sur un DPE sans date. La vente persiste `etiquette_dpe`,
  `periode_construction`, `date_etablissement_dpe` (`DATE`) et `version_dpe` (migration
  011). La date/version datent le diagnostic pour la gestion des **ruptures de réforme
  DPE** en aval (#208 pondération temporelle, #127 DAT-4) ; elles ne sont pas encore
  lues au calcul.
- ⚠️ **MAIS la base prod/lab est ré-ingérée SANS bornes (S12)** : ces bornes ont été
  élargies à l'ingestion réelle car le plafond censurait le haut de gamme et
  sous-estimait le gain E→C de +3 à +6 pts. `data.vente` est donc **brute**. Réduire
  ces bornes à de simples garde-fous est un point ouvert (RECAP §6).

**Éligibilité au calcul** (`regression._eligible`) — une vente entre dans la régression si :
- `prix` non nul et **> 0** (log), `surface_bati` non nulle et **> 0**,
- `etiquette_dpe` **non nulle**, `latitude`/`longitude` **non nulles**,
- période : filtrée **seulement si** `restreindre_bati_ancien=true`. En prod c'est
  **`false`** (S20) → **tous les millésimes de construction** comptent.
- `surface_terrain` absente → traitée comme **0** (log1p).

- **Mutations multi-communes exclues** (`multi_communes.filtrer_multi_communes`,
  filtre de pool dans `orchestration.compute_departement`) : l'ingestion étant **par
  commune**, une vente à cheval sur plusieurs communes produit **une ligne par
  commune**, chacune portant le **prix total** de la vente mais la **surface de sa
  seule commune** → le rapport prix/surface est faux (€/m² surestimé). Toutes les
  copies d'une même `(id_mutation, millesime)` présente sous **plus d'un `code_insee`**
  sont écartées **avant** la régression. Détecté au niveau du pool : les communes
  d'une même vente étant contiguës (dept + limitrophes), toutes ses copies y sont, le
  groupe est complet. Toujours actif (règle de correction, non configurable). L'audit
  de validité (`controle_qualite`, code `mutation_multi_communes`) les signale par
  ailleurs en alerte — elles ne sont pas supprimées de `data.vente`, seulement du calcul.

**Pool** (`pool_repository.load_pool`) : ventes du département **+ départements
limitrophes** (`limitrophes.LIMITROPHES`, débordement #18/#91), sur la **fenêtre de
millésimes** demandée, `type_local='Maison'`, triées par `id_mutation` (ordre
canonique INV-5). Charge aussi `date_etablissement_dpe` (#211) pour le filtre de
rupture ci-dessous. La carte `LIMITROPHES` couvre **toute la métropole** (96
départements, Corse 2A/2B comprise, #223) : le débordement joue au bord de tout
département, plus seulement dans les zones pilotes. Un voisin absent de la base
ne contribue simplement à rien. L'adjacence a été dérivée des contours
départementaux (`docs/carte-validation/dept-contours.geojson`, frontière réelle
partagée, contacts ponctuels aux tripoints écartés) puis figée dans le module.

- **Rupture de réforme DPE** (#215, `ruptures.filtrer_regime_dpe`, calendrier
  `dpe_reform_boundaries`) : une réforme du DPE (méthode 3CL 2021-07-01, recalcul
  petites surfaces 2024-07-01, réforme ~2026) casse la comparabilité des étiquettes
  (un « D » d'avant ≠ un « D » d'après). Pour une maille sous millésime N, on **écarte
  du pool** les ventes dont le DPE (`date_etablissement_dpe`) précède la **dernière
  réforme ≤ `{N}-12-31`** — on ne poole pas à travers une réforme. Une vente au DPE
  **sans date** est **conservée** (on n'exclut que ce qu'on peut positivement dater
  avant la borne) ; une réforme **future** (> N) n'a aucun effet. La cascade (#169)
  absorbe la baisse d'effectif. **Calendrier vide par défaut** = aucun filtrage
  (non-régression exacte) ; à activer au VPS avec les réformes **matérielles pour les
  maisons** (le recalcul 2024 des < 40 m² ne les touche quasi pas).

- **Fenêtre glissante** (#177, `fenetre_millesimes`) : le pool empile les
  `fenetre_millesimes` derniers millésimes finissant au millésime de référence N
  (ex. `fenetre=3` → N, N-1, N-2), en une requête (`millesime = ANY(...)`). Le
  bénéfice vert est structurel et lent : l'estimer sur plusieurs années réduit la
  variance de rééchantillonnage (élasticités, classes DPE rares en rural) et rend
  les écarts REG-1 inter-millésimes faibles par construction (fenêtres
  recouvrantes). La maille reste **publiée sous le millésime N** ; ce sont les
  effets fixes année (§4) qui absorbent la tendance de niveau, l'étiquette DPE
  mesurant l'effet **structurel moyen** de la fenêtre.
- **Défaut = 2** (W2). La validation multi-départements (15 depts, 2021-2024) a
  tranché : la fenêtre est un **régulariseur** (queue d'erreur inter-année p90
  −22 %, monotonie DPE en hausse), mais l'instabilité inter-année ne se prédit pas
  maille par maille (gating sélectif écarté). Surtout, le **premium DPE dérive**
  (amplitude mono-année ~+15 %/an) et les effets fixes année corrigent le niveau de
  prix **pas** cette dérive : pooler à poids égaux introduit donc un biais de retard
  (W3 ≈ −12 %, W2 ≈ −6 % sur le premium de l'année N). **W2** est le meilleur
  compromis biais-variance par défaut (W3 trop stale, W1 mono-millésime trop
  bruité). 1 = comportement historique exact ; surcharge par
  `VV_MOTEUR_FENETRE_MILLESIMES`. La vraie solution — **pondération temporelle
  décroissante** (poids ↓ vers le passé, généralise le défaut plat) — est l'objet
  de #208.

## 4. Modèle de régression (`regression.fit`)

Moindres carrés **pondérés**, en **logarithme du prix** :

```
log(prix) ~ 1 + log(bâti) + log1p(terrain) + [log(indice_local)]
            + indicatrices_DPE + indicatrices_période
            + indicatrices_commune(désactivé) + indicatrices_année
```

- **Poids** : gaussien spatial `exp(-d²/2σ²)`, `d` = distance haversine au centroïde
  commune, **multiplié** (#208) par un poids **temporel décroissant**
  `exp(-(N − millésime)/τ)` quand `decroissance_temporelle_tau` (= τ) et l'année de
  référence N sont fournis (§4bis). `τ = None` (défaut) ⇒ poids gaussien seul
  (poids égaux entre millésimes, comportement #177 exact).
- **Référence DPE = D** (`reference_dpe`, coef 0). Les autres classes présentes ont
  un coefficient ; référence des autres catégorielles = 1er niveau trié (implicite).
- **Sorties** :
  - `coef_dpe` = dict `{classe: effet_log}` (base D). **En log** : gain % = `exp(coef)−1`.
  - `coef_m2_bati` = `beta[1]` (élasticité log-log du bâti), `coef_m2_terrain` = `beta[2]`.
  - `se_dpe` = dict `{classe: écart-type}` (#249, `_erreurs_types`) : écart-type
    d'échantillonnage de chaque effet DPE — `√diag(σ̂²·(XᵀWX)⁻¹)`, `pinv` + garde dof
    (`n ≤ p ⇒ +∞`). **Régresseur de précision** du pooling hiérarchique (poids
    `w = τ²/(τ²+SE²)`) ; matière première des priors τ̂², **persistée** dans l'historique
    (§12) mais **pas encore consommée** (shrinkage = lots suivants de #249).
  - `r2` pondéré, mesuré **sur l'échelle log** — **diagnostic seulement**, jamais publié (#23).
- **Indice local** (S24, `indice_local=true`) : régresseur = médiane du prix/m² des
  **20 ventes les plus proches** (`voisins_indice_local`), le bien lui-même exclu.
  Neutre (log 1 = 0) si l'étendue de l'échantillon < `etendue_minimale_indice` (0,002°).
- **Effets fixes période** : **gardés** (corrélés au DPE ; sans eux l'étiquette capte
  l'époque de construction — mitigation #115).
- **Effets fixes année** : indicatrices par millésime présent dans le pool. Sur une
  fenêtre glissante multi-années (#177, §3), elles **captent la tendance de niveau**
  entre millésimes, de sorte que l'étiquette DPE mesure l'effet **structurel moyen**
  de la fenêtre et non la dérive de marché. Sur un pool mono-millésime, aucune
  indicatrice année n'est générée (un seul niveau).
- **Effets fixes commune** : **désactivés** (S22, `effets_fixes_commune=false`) — ils
  sur-ajustaient (r² gonflé, erreur CV pire) ; l'indice local + σ portent la localité.

### 4bis. Pondération temporelle décroissante (#208, `poids_temporel`)

Poids multiplicatif `exp(-(N − millésime)/τ)` appliqué **par-dessus** le poids
gaussien spatial dans `regression._weights` (donc dans `fit` **et** dans les fits
internes de la validation croisée — l'erreur médiane mesure l'estimateur publié).

- **Motivation** : la fenêtre glissante (#177) poole plusieurs millésimes à **poids
  égaux** pour réduire la variance. Mais le **premium DPE dérive** (~+15 %/an,
  validation 15 depts) et les effets fixes année corrigent le **niveau de prix**, pas
  la dérive du premium lui-même. Pooler à plat estime donc la **moyenne** du premium
  sur la fenêtre → biais de retard sur l'année N. Le poids temporel fait peser moins
  les millésimes anciens : l'estimation se rapproche du premium récent.
- **τ (`decroissance_temporelle_tau`)** = constante de temps en années ; à une
  ancienneté `N − millésime = τ` le poids vaut `exp(-1)`. **`None` = désactivé**
  (poids égaux, non-régression #177 exacte) — c'est le **défaut**. τ grand ⇒ quasi
  poids égaux ; τ petit ⇒ quasi mono-année.
- **Composition avec la fenêtre** : `fenetre_millesimes` décide *quels* millésimes
  entrent dans le pool ; τ décide *combien* chacun pèse. Orthogonaux.
- **Année de référence N** : le millésime publié de la maille (passé explicitement,
  jamais dérivé par `max()` — sous CV un pli peut perdre l'année N). La ligne reste
  **ancrée sur N** (§3) ; le poids temporel rapproche l'estimation de N.
- **Périmètre** : s'applique à l'**estimateur publié** (fit) et à son **erreur** (CV).
  Les étapes **structurelles** en amont — écrêtage des outliers (§8) et fusion en
  bandes (§14) — restent sur le **poids spatial seul** : un outlier ancien reste un
  outlier, et la structure de bandes doit rester stable, indépendante de la récence.
- **Statut** : opt-in (`VV_MOTEUR_DECROISSANCE_TEMPORELLE_TAU`), désactivé par défaut.
  **Calibration faite** (#208, 6 depts denses+ruraux, N=2024, gain E→C à périmètre
  constant) : la dérive du premium est confirmée (~+16 %/an), mais **une fenêtre
  profonde + τ ajoute plus de retard qu'elle n'en corrige** — le meilleur W4+τ
  (retard −2,0) reste sous **W2 à poids plats** (retard −1,2, et meilleure erreur CV).
  **Décision : garder W2 par défaut, τ reste opt-in, pas de bascule du défaut.** Le
  mécanisme reste disponible pour un territoire à très forte dérive.

### 4ter. Pooling partiel hiérarchique (#249, `pooling.py`, `batch.pool_hierarchique`)

Empirical-Bayes **entre communes** (là où #177/#208 régularisent dans le temps) : en
rural, les effets DPE d'une petite maille sont bruités, et la cascade #169 est
**discontinue** (n=32 publie un fit local très bruité, n=28 bascule sur tout l'EPCI). Le
pooling tire chaque effet DPE communal ``β̂`` vers l'effet **départemental** ``μ``
(le fit poolé, `compute_maille_departement`) proportionnellement à sa **précision** :

```
θ̂ = μ + w·(β̂ − μ),   w = τ²/(τ²+SE²)
```

- ``SE²`` = variance d'échantillonnage de l'effet (`regression.fit` → `se_dpe`, §4) ;
- ``τ²`` = **variance inter-communes** de l'effet, estimée en **forme fermée** (méthode
  des moments autour du centre fixe ``μ`` : ``τ̂² = max(0, (Σ w(β̂−μ)² − M)/Σw)``,
  ``w = 1/SE²``, une passe par (département, classe) — `pooling.variance_inter`).
- **Poids piloté par les données** — aucune constante à régler. Classe bien estimée
  (``SE`` petit) ⇒ ``w → 1`` (inchangée) ; mal estimée (``SE`` grand, voire ``+∞`` sans
  degré de liberté) ⇒ ``w → 0`` (tirée vers ``μ``) ; communes homogènes (``τ̂² → 0``) ⇒
  pooling complet (``μ`` partout) ; réellement divergentes ⇒ pas de pooling.
- **Périmètre** : les **effets DPE** seulement (pas les élasticités) ; la **référence D**
  n'est jamais tirée (ancre) ; une classe fusionnée cible l'effet de son **représentant
  de bande** (#169) ; une classe sans cible ou portée par **< 2** communes reste locale.
- **Fourchette de l'effet (#260)** : `ic_dpe` découle désormais de l'**écart-type de
  l'effet**, `coef ± IC_Z·SE` (`IC_Z = 1`, soit ±1 SD ≈ 68 %, `batch._intervalle_se`) —
  **pas** de l'erreur médiane. Pour une classe **régularisée**, le SE est **postérieur**
  (`√variance_posterieure(τ², SE²) = √(τ²·SE²/(τ²+SE²))`, `pooling.variance_posterieure`)
  donc **plus resserré** que le SE local : le bénéfice du shrinkage (emprunt de force au
  département) est visible dans la fourchette. Pour une classe **non tirée** portant un
  SE, l'IC est **SE local** (`coef ± SE`) ; repli sur l'heuristique erreur médiane
  seulement pour une classe **sans** SE (rare). ⚠️ Ces fourchettes SE-based sont souvent
  **plus larges** que l'ancienne heuristique et peuvent **traverser zéro** (un effet non
  distinguable de 0) — c'est l'incertitude réelle du coefficient, plus honnête. **Lot
  data** : l'app doit encore lire `ic_dpe` pour la fourchette servie/PDF (aujourd'hui
  dérivée de `erreur_mediane`).
- **Statut** : opt-in (`VV_MOTEUR_POOLING_HIERARCHIQUE`), **désactivé par défaut** (comme
  τ #208). Câblé dans `orchestration.compute_departement` **avant** la cascade (§7) ;
  ``se_dpe`` est **persisté** dans l'historique (§12) — matière première recalculable des
  priors. **Calibration VPS à venir** (stabilité rurale, taux de publication LOCAL, biais,
  erreur CV) avant toute bascule du défaut.

## 5. Sélection par rayon adaptatif (`radius.adaptive_selection`)

- Élargit le rayon **10 → 15 → 20 → 30 → 50 km** (`radii_km`) jusqu'à atteindre
  `min_sample = 80` ventes. Retient le plus petit rayon suffisant.
- Si 50 km ne suffit pas : `suffisant = False` (zone très rurale) → repli départemental.
- `distance_min_sample` = distance continue à la 80ᵉ vente = mesure de densité (pour σ).

## 6. Pondération σ (`batch._sigma_local`, `sigma_mode`)

- **`densite`** (défaut, S23) : σ = `distance_min_sample / sigma_diviseur` (diviseur 1,0).
  Densité continue (distingue une ville très dense d'une ville dense).
- `rayon` : σ = rayon retenu / diviseur. `fixe` : σ = `sigma_km` (10).
- **Plancher** `sigma_min = 3.0 km` (protège les mailles fragiles).
- Mode **départemental** : σ = `departemental_sigma_km = 1000` → poids ~uniformes
  (une régression pour tout le département).

## 7. Décision de mode (`degraded.decide_mode`)

Avec `seuil = min_local_sample = 30`, `n_local` = éligibles dans la sélection,
`n_departement` = éligibles du **seul département** (`_meme_departement`, voisins **exclus**) :

| Condition | Mode | Effet |
|---|---|---|
| `n_local ≥ 30` | **LOCAL** | régression locale, σ densité, rayon retenu |
| sinon `n_departement ≥ 30` | **DÉPARTEMENTAL** | σ=1000, rayon=null, **bandeau dégradé** |
| sinon | **REFUS** | pas de coefficient |

Ce choix interne à `compute_maille` reste piloté par l'**effectif** (n). Mais au
niveau de l'orchestration, une **cascade géographique** (#169) l'enveloppe : si le
cran LOCAL ne produit pas de maille **publiable** (absente, ou calculée mais hors
invariants par-maille — INV-4 erreur ≤ 30 %, INV-2, INV-1, INV-3 rayon), on **descend
d'un cran** : d'abord **EPCI** (`compute_maille_epci` : ventes des communes du même
EPCI, avec bandes), puis **DÉPARTEMENT** (`compute_maille_departement` : ventes du seul
département, avec bandes) en dernier filet. Le premier cran qui publie l'emporte ;
si aucun ne tient, la commune sort hors zone.

**Pooling partiel (#249, opt-in, §4ter)** : quand `pooling_hierarchique` est actif, les
mailles LOCAL sont **régularisées vers le fit poolé départemental AVANT** cette cascade —
une petite maille bruitée, rapprochée d'un parent monotone/plausible, passe alors les
invariants au lieu de basculer d'un cran. La cible (fit poolé) est le **parent du dernier
cran** : calculée une fois en tête de boucle et réutilisée (mémoïsation #175). La cascade
reste le **filet** : une maille LOCAL *shrinkée* qui rate encore un invariant descend
EPCI → DÉPARTEMENT comme sans pooling. Désactivé, `calculees` est inchangé.

## 8. Écrêtage des outliers (`trimming.ecreter_residu`, S32 V2)

Actif si `ecretage="residu"` (défaut) **et** `n ≥ ecretage_seuil_n = 100`. Premier fit
→ résidu log par vente → pondéré par le poids gaussien → normalisé par la **MAD**
(× 1,4826). Vente retirée si `|z| > ecretage_k = 3`. Cible les points mal ajustés
**et** proches (influents). Seul garde-fou outliers, la base étant sans bornes.

## 9. Publication des classes DPE (`batch.compute_maille`)

- `effectifs_dpe` = comptage par classe sur l'échantillon (post-écrêtage).
- **Classe publiable** si effectif `≥ min_class_sample = 20` (S21). En deçà, classe écartée.
- Il faut **`≥ min_classes_publiees = 2`** classes (dont la référence), sinon **REFUS**.
- ⚠️ Les constantes `MIN_CLASS_SAMPLE=15` / `MIN_CLASSES_PUBLIEES=2` de `batch.py` sont
  **legacy** : `compute_maille` utilise `config.min_class_sample` (=20). La config fait foi.

## 10. Erreur médiane — validation croisée (`cross_validation.median_relative_error`)

- **5 plis déterministes** (`cv_folds=5`, appartenance `index % 5` sur ordre canonique).
- Entraîne sans un pli, prédit ses ventes, erreur relative `|prédit−prix|/prix`.
- Métrique publiée = **médiane en %**. C'est **LE juge** de qualité (pas le r²).

## 11. Invariants de publication (`invariants.py`)

Une maille qui viole un invariant **n'est pas publiée** (coefficient antérieur effacé).

- **INV-4** (`evaluer_invariants_publication`) — qualité :
  - `erreur_mediane < erreur_mediane_max_pct_degrade` (**30 %**, plafond dégradé #169) —
    entre le seuil standard `erreur_mediane_max_pct` (**20 %**) et ce plafond, la maille
    est **publiée en tier dégradé** (son `palier` porte le bandeau) au lieu du refus sec ;
  - `r2 > r2_min` (**0,30**) — garde-fou technique, jamais exposé ;
  - `n_transactions ≥ min_local_sample` (**30**).
- **INV-2** (`evaluer_signe_ordre_grandeur`) — signes/ordres en log (si classes publiées) :
  - gain E→C = `coef_C − coef_E` **> 0** et **≤ `gain_dpe_ec_max` (0,70)** ;
  - gain E→D `<` gain E→C (effet de seuil) ;
  - élasticité bâti ∈ [`elasticite_bati_min` 0,30 ; `elasticite_bati_max` 1,50], **> 0** ;
  - élasticité terrain **> 0** et **< bâti**.
- **INV-1** (`evaluer_monotonie_dpe`) — monotonie `coef(A) > … > coef(G)` (classes publiées) :
  - une inversion (meilleure classe au coef plus bas) est **tolérée** (tracée, publiée)
    si les classes sont **adjacentes** ET la moins fournie < `inversion_dpe_seuil` (**30**) ;
  - toute autre inversion est **bloquante**. ⚠️ Certaines inversions sont **robustes**
    (Toulouse F/G, Foix C/D) — pas du bruit (voir #115).
- **INV-3** (`evaluer_coherence_geographique` + `detecter_ruptures_limitrophes`) :
  - rayon retenu **≤ `rayon_max_km` (50)** (sauf mode départemental) ;
  - rupture entre communes limitrophes : `coef_m2_bati` avec facteur **> `rupture_facteur_max`
    (2,0)** → **bandeau d'alerte de bord non masquable**, mais la maille **reste publiée**.
- **INV-5** — reproductibilité : ordre canonique `id_mutation` partout (pool, plis, sommation).

### REG-1 — écart au millésime précédent (#129, `ecarts.py`)

Dernière porte, **après** les invariants, sur une maille par ailleurs publiable. Chaque
maille calculée est comparée à son millésime précédent, **puis archivée** dans
`data.coefficient_maille_historique` (versionnée par `code_insee`+`millesime`) — la
comparaison précède l'archivage depuis REG-2 (#130), car le verdict part **avec**
l'archive (`classe_ecart`, `ecart`, `ecart_coefficient`) :

- **métrique** : le palier porte sur le **signal produit — les effets DPE** (#176).
  Écart relatif sur l'**effet `exp(coef)`** des classes DPE communes (un effet log peut
  bouger beaucoup sans que le prix bouge) ; la maille retient le **pire** coefficient.
  Un changement de l'ensemble des classes publiées (rebanding) vaut au moins une alerte ;
- **élasticités informatives** (#176) : `coef_m2_bati`/`coef_m2_terrain` sont comparées
  sur la valeur brute mais **plafonnées à `alerte`** — jamais un **blocage**. Ce sont des
  régresseurs internes invisibles de l'utilisateur et instables par rééchantillonnage
  annuel (surtout le terrain : écart p90 jusqu'à 221 % sur le VPS, quasi toujours proche
  des bornes). Le test réel de REG-1 (VPS, 2023→2024, #129) montrait qu'elles bloquaient
  **57 à 94 %** des mailles, presque toujours à cause du terrain ; en **DPE-seul** la part
  bloquée retombe à **1-12 %**, et surtout **différenciée par territoire** (muette là où le
  bénéfice vert est stable, active là où il a réellement bougé). À classe égale, le signal
  produit (DPE, rebanding) l'emporte sur une élasticité dans le **libellé** du coefficient
  en cause (option B) ; à rang égal, le plus grand écart est retenu ;
- **paliers** : `< ecart_alerte_pct` (**10 %**) normal ; jusqu'à `ecart_blocage_pct`
  (**25 %**) **alerte** (publiée, signalée pour revue) ; au-delà **blocage** (DPE
  uniquement) — la maille est archivée mais **écartée de la publication** du millésime
  (l'ancienne valeur vive reste servie jusqu'à déblocage explicite, REG-2). Blocage
  **par maille**, jamais du batch entier.

Prérequis INV-5 satisfait (CV déterministe → l'écart est de la dérive, pas de l'aléa). Le
batch renvoie un `RapportEcarts` dédié (une ligne par maille) pour l'écran de supervision (#45).

### DAT-3 — taux d'appariement DVF ↔ DPE (#126, `appariement.py`, `dat3.py`)

Porte **en amont du calcul**, par département : l'appariement DVF ↔ DPE est la charnière du
modèle (sans étiquette DPE rattachée, `regression._eligible` écarte la vente). Un appariement
qui s'effondre ne lève aucune erreur — il produit des coefficients sur un échantillon biaisé.

- **métrique** : le taux `ventes_appariées / mutations_insérées` du département, déjà **compté
  à l'ingestion** et **archivé par millésime** dans `data.rapport_batch` (#131). DAT-3 le relit
  (`db.rapport_batch_repository.lire_taux_appariement` / `…_precedent`, dernière exécution du
  millésime).
- **garde-fous** (`appariement.evaluer_appariement`) : blocage si le taux passe **sous**
  `taux_appariement_min_pct` (**60 %**, critère de sortie de J1 rendu opposable), ou s'il
  **chute de plus de** `chute_appariement_max_pct` (**10 points**) vs le millésime précédent.
  Sans précédent (premier millésime), seul le plancher s'applique.
- **effet** (`dat3.porte_dat3`, câblé dans la commande CLI `compute`) : un département bloqué
  **n'est pas calculé** — aucun `coefficient_maille_historique` écrit, l'ancien millésime reste
  servi ; la CLI journalise le motif et sort en erreur. Ingestion et calcul étant deux commandes
  séparées (§5), la porte s'évalue **au calcul** en relisant le taux archivé. Sans taux mesuré
  (aucune ingestion, ou 0 mutation), rien à barrer.

### DAT-4 — distributions (prix, surfaces, étiquettes DPE) (#127, `distributions.py`, `dat4.py`)

Porte **en amont du calcul**, par département : confronte les distributions **observées**
(mesurées et archivées à l'ingestion, §3) à des fourchettes de plausibilité et à
l'ingestion précédente. Une médiane départementale grossièrement fausse trahit une
ingestion cassée (prix en centimes, bâti et terrain intervertis) que DAT-1 ne voit pas —
les colonnes existent, seul leur contenu est aberrant.

- **plausibilité (BLOQUE)** (`distributions.evaluer_distribution`, motifs cumulables) :
  prix médian dans `[prix_median_min ; prix_median_max]`, surface bâtie médiane dans
  `[bati_median_min ; bati_median_max]` (60-160 m², distinct des bornes 20-400 de la
  saisie pro #28), part de ventes à **terrain nul ou hors bornes §7** sous
  `terrain_aberrant_max_pct`. Bornes **incluses** ; une médiane indéfinie (aucune valeur)
  n'est pas un motif.
- **répartition DPE (ALERTE seule, jamais un blocage)** : si la part d'une classe DPE se
  déplace de plus de `dpe_distribution_ecart_max_pct` (points) vs le millésime précédent,
  DAT-4 **alerte** sans arrêter le pipeline — une réforme DPE (2026) déplace
  **légitimement** la distribution et appelle une décision humaine (recalibrer, repartir
  d'un millésime de référence), pas un arrêt. Sans précédent (premier chargement), non
  évaluée.
- **effet** (`dat4.porte_dat4`, câblé dans la commande CLI `compute` après DAT-3) : la
  plausibilité en échec **n'est pas calculée** (comme DAT-3, sortie en erreur, ancien
  millésime servi) ; l'alerte DPE est journalisée mais **le calcul tourne**. Sans
  distribution archivée (ingestion antérieure à DAT-4) ou effectif nul, rien à juger.

### REG-2 — publication manuelle d'un millésime (#130, `db/publication_repository.py`)

Un millésime n'est **jamais publié automatiquement**. Le batch **calcule et archive** ;
il ne touche plus `data.coefficient_maille`, ni pour écrire une maille retenue, ni pour
effacer une maille refusée. Sans cette seconde moitié, un millésime calculé puis
abandonné aurait déjà modifié ce que voient les clients.

- **Refus différé** : une commune non calculable est inscrite dans `data.maille_refusee`
  (`code_insee`+`millesime`+motif). Elle ne sort du service qu'à la publication.
- **Publier** (`data.publier_millesime(millesime, auteur)`) projette l'historique du
  millésime vers la table vive, **sauf** les mailles en `classe_ecart = 'blocage'`, puis
  applique les refus du millésime et inscrit la décision dans `data.publication_millesime`.
- **Abandonner** (`data.abandonner_millesime`) n'a **aucun effet de bord** : l'archive
  reste, la table vive n'est pas touchée. Seule la décision est enregistrée, pour que le
  millésime cesse d'être proposé.
- **Débloquer une maille** (`data.publier_maille`) est **refusé tant que le millésime
  n'est pas publié** : une commune seule en N au milieu d'un parc en N-1 serait l'état
  mixte que REG-2 cherche justement à éviter. C'est une correction *après* publication.

La logique vit dans des fonctions SQL `SECURITY DEFINER` (migration 012), pas dans l'app :
celle-ci est en **lecture seule** sur le schéma `data` (décision D6) et ne reçoit que le
droit de les invoquer. Le contrat A/B tient — le pipeline reste réécrivable sans toucher
à l'app.

## 12. Sortie — `coefficient_maille` (`batch.MailleCoefficient`)

Colonnes : `code_insee` (PK), `millesime`, `rayon_retenu_km` (null si départemental),
`n_transactions`, `r2`, `erreur_mediane`, `coef_m2_bati`, `coef_m2_terrain`,
`coef_dpe` (JSONB), `ic_dpe` (JSONB), `alerte`, `palier`, `mode`, `bandes`, `calcule_le`.
- **`mode`** : cran de la cascade retenu (`local`/`epci`/`departemental`, #169) — la
  restitution en dérive la granularité affichée et la fiabilité (EPCI et DÉPT ont tous
  deux `rayon` null, seul `mode` les distingue).
- **`bandes`** (#169, crans EPCI/DÉPT) : mapping `classe DPE → représentant` des bandes
  fusionnées (ex. `{"F": "F", "G": "F"}`), **vide au cran LOCAL**. Sans lui, une maison
  d'une classe fusionnée (G) n'aurait aucun coefficient côté restitution.
- **`palier`** (#169, `degraded.palier_erreur`) : tier de fiabilité de la maille —
  `STANDARD` si `erreur_mediane < erreur_mediane_max_pct` (20 %), `DEGRADE` si sous
  `erreur_mediane_max_pct_degrade` (30 %). Distinct de `alerte` (réservé à INV-3
  bord) : la restitution en dérive le bandeau « estimation dégradée ». Le tier
  DEGRADE est désormais **publié** (INV-4 gate au plafond 30 %) **et persisté**
  (migration 009).
- **`ic_dpe`** : intervalle par classe. Hors pooling, intervalle empirique **heuristique**
  (S29) — `coef ± |coef|·(erreur_mediane/100)`. Après pooling (#260), fourchette
  **SE-based** `coef ± IC_Z·SE` (SE **postérieur** pour une classe régularisée, SE local
  sinon ; §4ter). Deux régimes coexistent tant que les crans non poolés n'ont pas convergé.
- **`effectifs_dpe`** : effectif observé par classe DPE publiée, **persisté** (JSONB,
  migration 009) — débloque la tolérance INV-1 au rechargement (RECAP §6, #131).
- **`se_dpe`** (#249) : écart-type par classe DPE publiée (§4), **persisté dans
  l'historique** (`coefficient_maille_historique`, JSONB, migration 017) — matière
  première recalculable des priors du pooling hiérarchique. **Non** sur la table vive
  `coefficient_maille` (régresseur interne, jamais servi à l'app).
- Restitution (`app`) : indicateur de fiabilité **Solide/Correct/Fragile** dérivé de
  (n, rayon) ; rayon null (repli EPCI/départemental) ⇒ toujours **Fragile** + bandeau,
  `mode` distinguant les deux crans.

## 13. Config gagnante (défauts `CalculConfig`, surcharge `VV_MOTEUR_*`)

| Paramètre | Défaut | Note |
|---|---|---|
| `segment` | `Maison` | maisons seules |
| `restreindre_bati_ancien` | `false` | S20, tous millésimes |
| `radii_km` | 10,15,20,30,50 | rayon adaptatif |
| `rayon_max_km` | 50 | plafond INV-3 |
| `min_sample` | 80 | cible du rayon |
| `min_local_sample` | 30 | seuil mode + n mini INV-4 |
| `min_class_sample` | 20 | S21, publication classe |
| `min_classes_publiees` | 2 | dont la référence |
| `fenetre_millesimes` | 2 | #177 — millésimes empilés (fenêtre glissante) ; W2 = compromis biais-variance validé (15 depts) ; 1 = mono-millésime historique ; #208 pondérera |
| `decroissance_temporelle_tau` | `None` | #208 — τ du poids temporel `exp(-(N−millésime)/τ)` ; `None` = désactivé (poids égaux #177) ; §4bis |
| `dpe_reform_boundaries` | `()` | #215 — calendrier de réformes DPE (dates ISO) ; écarte les DPE d'un régime antérieur à la dernière réforme ≤ N ; vide = aucun filtrage ; §3 |
| `inversion_dpe_seuil` | 30 | tolérance INV-1 |
| `sigma_mode` / `sigma_diviseur` / `sigma_min` | `densite` / 1,0 / 3,0 | S23 |
| `departemental_sigma_km` | 1000 | repli quasi-uniforme |
| `reference_dpe` | `D` | base coef 0 |
| `voisins_indice_local` | 20 | S24 |
| `effets_fixes_commune` | `false` | S22 |
| `indice_local` | `true` | S24 |
| `pooling_hierarchique` | `false` | #249 — pooling partiel empirical-Bayes des effets DPE vers le fit poolé départemental (§4ter) ; `false` = désactivé (non-régression exacte) ; opt-in, calibration VPS à venir |
| `cv_folds` | 5 | validation croisée |
| `erreur_mediane_max_pct` | 20 | seuil du tier standard (classe le `palier`) |
| `erreur_mediane_max_pct_degrade` | 30 | plafond de publication INV-4 (tier dégradé #169) |
| `ecart_alerte_pct` / `ecart_blocage_pct` | 10 / 25 | paliers d'écart au millésime précédent (REG-1 #129) |
| `taux_appariement_min_pct` / `chute_appariement_max_pct` | 60 / 10 | porte DAT-3 (#126) : plancher d'appariement DVF↔DPE et chute bloquante vs millésime précédent |
| `ecart_volumetrie_max_pct` | 30 | assertion DAT-2 (#125) : écart de volumétrie (mutations insérées) vs millésime précédent au-delà duquel une **alerte** (non bloquante) est journalisée en fin d'ingestion |
| `prix_median_min` / `prix_median_max` | 40 000 / 1 000 000 | DAT-4 (#127) : fourchette de plausibilité du prix médian départemental (BLOQUE) |
| `bati_median_min` / `bati_median_max` | 60 / 160 | DAT-4 (#127) : fourchette de la surface bâtie médiane (BLOQUE ; ≠ 20-400 saisie pro #28) |
| `terrain_aberrant_max_pct` | 50 | DAT-4 (#127) : part max de ventes à terrain nul/hors bornes §7 (BLOQUE) — à calibrer VPS |
| `dpe_distribution_ecart_max_pct` | 15 | DAT-4 (#127) : déplacement max (points) de la part d'une classe DPE vs précédent → ALERTE (jamais un blocage) |
| `fraicheur_max_mois` | 12 | DAT-5 (#128) : ancienneté max (mois) de la mutation la plus récente vs date d'exécution → ALERTE (jamais un blocage) |
| `r2_min` | 0,30 | garde-fou |
| `gain_dpe_ec_max` | 0,70 | plafond gain log INV-2 |
| `elasticite_bati_min/max` | 0,30 / 1,50 | INV-2 |
| `ecretage` / `ecretage_seuil_n` / `ecretage_k` | `residu` / 100 / 3,0 | S32 |
| `price_min/max`, `bati_min/max`, `terrain_min/max` | 30k/900k, 50/250, 50/5000 | §7 — **non appliqué en prod (sans bornes)** |

## 14. Couverture rurale (#169) — livraison en cours

La **cascade de couverture** (voir [`docs/lab/resultats-couverture-rurale.md`](lab/resultats-couverture-rurale.md))
se câble par petits pas TDD. État :

- ✅ **Palier dégradé au LOCAL** : la maille porte son `palier` (§12) et INV-4 publie
  le tier dégradé jusqu'à 30 % (§11) — ouvre le rural sans descendre de cran.
- ✅ **Cran EPCI** (bandes adaptatives) : `batch.compute_maille_epci` poole les ventes
  des communes du même EPCI (une régression par EPCI, σ uniforme) avec bandes
  monotones ; câblé dans `orchestration` en repli d'un LOCAL non publiable (§7).
- ✅ **Cran DÉPARTEMENT** : `batch.compute_maille_departement` poole les ventes du seul
  département (voisins exclus) avec bandes monotones ; câblé en dernier cran de la
  cascade dans `orchestration` (§7). Cœur des crans EPCI/DÉPT factorisé (`_maille_par_bandes`).
- ✅ **Régression partagée & mémoïsée** (#175) : aux crans EPCI/DÉPARTEMENT, la
  pondération est **uniforme** (σ départemental) — le centre est le **barycentre des
  ventes du périmètre** (`_centroide_moyen`), pas le centroïde de la commune. La maille
  est donc **identique pour toutes les communes** de l'EPCI (resp. du département) et
  ne dépend pas de l'ordre d'itération (INV-5). `orchestration.compute_departement` la
  calcule **une seule fois** par EPCI / par département puis la réutilise (seul
  `code_insee` change dans la ligne écrite) — le coût passe de `O(communes × fit+bandes)`
  à `O(régressions distinctes × fit+bandes)`. `compute_maille_epci` /
  `compute_maille_departement` ne prennent donc plus de `centroid_lat/lon`. Sortie
  inchangée : une ligne `coefficient_maille` par commune.
- ✅ **Mode DÉPARTEMENTAL dégradé mémoïsé** (#200, `_maille_departementale`) : même
  principe pour le repli départemental **interne** de `compute_maille` (§7, mode #22
  sans bandes) — centre = barycentre des ventes du département, résultat identique pour
  toutes les communes du dept, calculé **une seule fois par batch** (`compute_communes`
  passe un cache `memo_departemental`) et réutilisé (seul `code_insee` change). Le mode
  LOCAL reste par commune (voisinage propre, régressions légitimement différentes).
- ✅ **Persistance** : migration 009 ajoute `mode`, `palier`, `bandes`, `effectifs_dpe`
  à `coefficient_maille` ; `upsert_coefficient` les écrit (contrat data→app complet).
- ✅ **Restitution `app`** : le lecteur (`CoefficientMailleDbal`) projette `mode`,
  `palier`, `bandes` ; `CoefficientMaille` résout une classe DPE fusionnée via sa
  bande (G servie par F) au lieu de refuser l'estimation. La restitution formule
  le cran retenu (`local` → rayon, `epci` → « intercommunal », `departemental` →
  « départemental »), affiche un **bandeau dégradé** propre au palier (20-30 %),
  et signale à l'usager les classes servies par bande. Ces trois signaux sont
  **figés dans l'instantané** (#33) pour un PDF régénéré à l'identique.

Ce référentiel décrit le moteur **tel qu'il tourne** : la cascade géographique
complète LOCAL → EPCI → DÉPARTEMENT est branchée, **persistée** et **restituée**
côté `app` (contrat data→app bouclé de bout en bout).

## 15. Décisions & réglages (traçabilité)

Config gagnante et protocole : [`docs/lab/RECAP.md`](lab/RECAP.md). Diagnostics #115 :
[`lab/diagnostic-inversion-FG-toulouse.md`](lab/diagnostic-inversion-FG-toulouse.md),
[`lab/diagnostic-115-type-et-rural.md`](lab/diagnostic-115-type-et-rural.md). Glossaire :
[`docs/GLOSSAIRE-STATISTIQUE.md`](GLOSSAIRE-STATISTIQUE.md).

**Décision #115 (coefficients DPE non monotones)** — tranchée et **en prod** : le biais
d'inversion (passoires plus chères que la moyenne à Toulouse) venait de trois causes
cumulées. Les trois corrections retenues sont implémentées : **(1)** modèle en **log-prix**
(coefficients en %, comparables entre communes, §4), **(2)** **effets fixes période de
construction** (44 % des `G` sont d'avant 1948 vs 2 % des `A`, §4), **(3)** **indice de
quartier leave-one-out** (médiane des 20 ventes les plus proches, bien exclu, §4). Restent
**décidés séparément** (hors #115) : le **pooling partiel** (tirer un coef communal à faible
effectif vers le départemental) et un **garde-fou de monotonie** à la publication — la
tolérance INV-1 (§11) couvre aujourd'hui les inversions robustes en attendant.
