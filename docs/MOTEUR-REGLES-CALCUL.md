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
- `cleaning.clean_mutations` applique les bornes §7 **par défaut** : prix ∈ [30 000 ;
  900 000] €, bâti ∈ [50 ; 250] m², terrain ∈ [50 ; 5 000] m² (si présent).
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

**Pool** (`pool_repository.load_pool`) : ventes du département **+ départements
limitrophes** (`limitrophes.LIMITROPHES`, débordement #18/#91), millésime donné,
`type_local='Maison'`, triées par `id_mutation` (ordre canonique INV-5).

## 4. Modèle de régression (`regression.fit`)

Moindres carrés **pondérés**, en **logarithme du prix** :

```
log(prix) ~ 1 + log(bâti) + log1p(terrain) + [log(indice_local)]
            + indicatrices_DPE + indicatrices_période
            + indicatrices_commune(désactivé) + indicatrices_année
```

- **Poids** : gaussien `exp(-d²/2σ²)`, `d` = distance haversine au centroïde commune.
- **Référence DPE = D** (`reference_dpe`, coef 0). Les autres classes présentes ont
  un coefficient ; référence des autres catégorielles = 1er niveau trié (implicite).
- **Sorties** :
  - `coef_dpe` = dict `{classe: effet_log}` (base D). **En log** : gain % = `exp(coef)−1`.
  - `coef_m2_bati` = `beta[1]` (élasticité log-log du bâti), `coef_m2_terrain` = `beta[2]`.
  - `r2` pondéré, mesuré **sur l'échelle log** — **diagnostic seulement**, jamais publié (#23).
- **Indice local** (S24, `indice_local=true`) : régresseur = médiane du prix/m² des
  **20 ventes les plus proches** (`voisins_indice_local`), le bien lui-même exclu.
  Neutre (log 1 = 0) si l'étendue de l'échantillon < `etendue_minimale_indice` (0,002°).
- **Effets fixes période** : **gardés** (corrélés au DPE ; sans eux l'étiquette capte
  l'époque de construction — mitigation #115).
- **Effets fixes commune** : **désactivés** (S22, `effets_fixes_commune=false`) — ils
  sur-ajustaient (r² gonflé, erreur CV pire) ; l'indice local + σ portent la localité.

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

## 12. Sortie — `coefficient_maille` (`batch.MailleCoefficient`)

Colonnes : `code_insee` (PK), `millesime`, `rayon_retenu_km` (null si départemental),
`n_transactions`, `r2`, `erreur_mediane`, `coef_m2_bati`, `coef_m2_terrain`,
`coef_dpe` (JSONB), `ic_dpe` (JSONB), `alerte`, `palier`, `calcule_le`.
- **`palier`** (#169, `degraded.palier_erreur`) : tier de fiabilité de la maille —
  `STANDARD` si `erreur_mediane < erreur_mediane_max_pct` (20 %), `DEGRADE` si sous
  `erreur_mediane_max_pct_degrade` (30 %). Distinct de `alerte` (réservé à INV-3
  bord) : la restitution en dérive le bandeau « estimation dégradée ». Le tier
  DEGRADE est désormais **publié** (INV-4 gate au plafond 30 %). ⚠️ Non persisté
  aujourd'hui (contrat `coefficient_maille` à étendre — cf. §14).
- **`ic_dpe`** : intervalle empirique **heuristique** (S29) — `coef ± |coef|·(erreur_mediane/100)`.
- ⚠️ **`effectifs_dpe` n'est PAS persisté** (porté en mémoire dans le batch) → bloque
  aujourd'hui la tolérance INV-1 au rechargement (RECAP §6).
- Restitution (`app`) : indicateur de fiabilité **Solide/Correct/Fragile** dérivé de
  (n, rayon) ; rayon null (repli départemental) ⇒ toujours **Fragile** + bandeau.

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
| `inversion_dpe_seuil` | 30 | tolérance INV-1 |
| `sigma_mode` / `sigma_diviseur` / `sigma_min` | `densite` / 1,0 / 3,0 | S23 |
| `departemental_sigma_km` | 1000 | repli quasi-uniforme |
| `reference_dpe` | `D` | base coef 0 |
| `voisins_indice_local` | 20 | S24 |
| `effets_fixes_commune` | `false` | S22 |
| `indice_local` | `true` | S24 |
| `cv_folds` | 5 | validation croisée |
| `erreur_mediane_max_pct` | 20 | seuil du tier standard (classe le `palier`) |
| `erreur_mediane_max_pct_degrade` | 30 | plafond de publication INV-4 (tier dégradé #169) |
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
- ⏳ **Persistance** : `coefficient_maille` doit porter `palier`/bandes/EPCI (contrat data→app).
- ⏳ **Restitution `app`** : bandeau dégradé, rendu par bande, amplitude affichée.

Ce référentiel décrit le moteur **tel qu'il tourne** : la cascade géographique
complète LOCAL → EPCI → DÉPARTEMENT est branchée ; restent la **persistance** du
`palier`/bandes dans `coefficient_maille` et la **restitution** `app`.

## 15. Décisions & réglages (traçabilité)

Config gagnante et protocole : [`docs/lab/RECAP.md`](lab/RECAP.md). Diagnostics #115 :
[`lab/diagnostic-inversion-FG-toulouse.md`](lab/diagnostic-inversion-FG-toulouse.md),
[`lab/diagnostic-115-type-et-rural.md`](lab/diagnostic-115-type-et-rural.md). Glossaire :
[`docs/GLOSSAIRE-STATISTIQUE.md`](GLOSSAIRE-STATISTIQUE.md).
