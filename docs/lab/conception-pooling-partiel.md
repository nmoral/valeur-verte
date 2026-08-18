# Note de conception — Pooling partiel hiérarchique (empirical Bayes) des effets DPE

> **Statut : proposition, à valider avant tout code.** Cette note fixe le *quoi* et le
> *comment* du pooling partiel **hiérarchique** (option A3) ; elle ne modifie pas encore
> le moteur. Une fois validée, elle guide l'implémentation TDD (opt-in, défaut désactivé)
> puis la calibration VPS, sur le modèle de la fenêtre glissante (#177) et de la
> pondération temporelle (#208).

Reste explicite de la décision #115 (« tirer un coefficient communal à faible effectif
vers le départemental ») et point ouvert de [`RECAP.md`](RECAP.md) §6.

---

## 1. Le problème

Le moteur estime les effets DPE **par commune** (cran LOCAL, `regression.fit`). En
**rural**, une classe DPE peut n'avoir que 20-30 ventes : l'effet estimé est **bruité**
(forte variance de rééchantillonnage). Deux symptômes déjà observés :

- **inversions de monotonie de bruit** (INV-1) sur classes rares — le premium E→C d'une
  petite maille peut être aberrant par simple aléa de tirage ;
- **cascade #169 brutale** : une commune dont le cran LOCAL rate un invariant bascule
  **entièrement** au cran EPCI puis DÉPARTEMENT — estimation **uniforme, identique pour
  toutes les communes du périmètre** (#175). Le passage est **discontinu** : `n_local=32`
  publie un fit local très bruité ; `n_local=28` reçoit l'estimation de tout l'EPCI. Rien
  entre les deux.

La fenêtre glissante (#177) et la pondération temporelle (#208) réduisent la variance
**dans le temps**. Le pooling partiel s'attaque à la variance **entre communes** :
régulariser chaque petite maille vers l'effet DPE départemental, **proportionnellement à
sa propre précision**.

---

## 2. Le modèle hiérarchique (A3)

Pour une classe DPE `c` fixée, chaque commune `m` d'un département a un **vrai effet**
`θ_{m,c}` (l'effet log de la classe `c` vs la référence D). Le fit local en donne une
estimation `β̂_{m,c}` de **variance d'échantillonnage** `v_{m,c} = SE_{m,c}²`. On pose
que les communes du département partagent une **loi commune** :

```
β̂_{m,c} | θ_{m,c} ~ Normal(θ_{m,c}, v_{m,c})      (bruit d'estimation, connu via SE)
θ_{m,c}           ~ Normal(μ_c, τ_c²)             (dispersion inter-communes)
```

- `μ_c` = **effet départemental** de la classe `c` — **cible du shrinkage** ;
- `τ_c²` = **variance inter-communes** de l'effet — *combien les communes diffèrent
  vraiment*, une fois retiré le bruit d'estimation.

L'estimateur de shrinkage (moyenne a posteriori) est un **mélange précision-pondéré** :

```
θ̂_{m,c} = μ̂_c + w_{m,c} · (β̂_{m,c} − μ̂_c)
w_{m,c} = τ̂_c² / (τ̂_c² + v_{m,c})
```

- classe **bien estimée** (`v` petit devant `τ²`) ⇒ `w → 1` ⇒ **quasi inchangée** ;
- classe **mal estimée** (`v` grand : peu de ventes/dof) ⇒ `w → 0` ⇒ **tirée vers `μ̂_c`** ;
- communes **cohérentes** (`τ̂² → 0`) ⇒ **pooling complet** (effet homogène → `μ̂_c` partout) ;
- communes **réellement divergentes** (`τ̂²` grand) ⇒ **pas de pooling** (signal local respecté).

**La grande propriété** : `w` est **entièrement piloté par les données** `(v, τ²)`. Aucune
constante `k` arbitraire à régler — le modèle décide combien tirer. La calibration
**valide** le résultat, elle ne tune plus un curseur.

---

## 3. La cible `μ̂_c` = fit départemental poolé (décision D-E)

`μ̂_c` = l'**effet DPE du cran départemental poolé** (`batch.compute_maille_departement` :
une régression sur les ventes du seul département, avec bandes). Choisi parce qu'il est :

- **déjà calculé et mémoïsé une fois par batch** (#175) — donc **naturellement préparé en
  amont**, disponible **avant** d'avoir ajusté toutes les communes (cf. §5, artefacts) ;
- **toujours défini** (parent universel) et **stable** (tout le département, σ uniforme).

**Subtilité bandes.** Le cran départemental fusionne des classes adjacentes en **bandes**
(#169). Pour tirer la classe locale `c`, on prend l'effet du **représentant de bande** de
`c` côté parent (`MailleCoefficient.bandes` + `coef_dpe`). Classe sans représentant côté
parent (très rare) ⇒ **non shrinkée** (`w=1`, repli sûr).

*Alternative écartée pour l'instant* : `μ̂_c` = **méta-moyenne à effets aléatoires** des
estimations communales (empirical-Bayes « pur », DerSimonian-Laird). Théoriquement plus
propre, mais c'est un **agrégat** (impossible à préparer avant la fin) et il mélange moins
bien avec l'architecture existante. Gardée comme **raffinement** si la calibration le
justifie (§8, D-H).

---

## 4. Estimer `τ̂_c²` — méthode des moments (forme fermée)

Autour de la cible `μ̂_c` (fixée, §3), la dispersion inter-communes s'estime en **forme
fermée** (une passe, pas d'optimisation) via l'identité de moment
`E[(β̂_m − μ)²] = τ² + v_m` :

```
w_m  = 1 / v_m
τ̂_c² = max( 0 ,  ( Σ_m w_m (β̂_{m,c} − μ̂_c)² − (M − 1) ) / Σ_m w_m )
```

(`M` = nombre de communes portant la classe `c` dans le département ; plancher 0.) C'est
la forme **DerSimonian-Laird à centre fixé** — DL standard quand `μ̂` est la méta-moyenne.
Les termes sont des **sommes additives** (`Σw_m`, `Σw_m(β̂−μ)²`) : accumulables commune par
commune, ou recalculables après coup sur l'archive (§5).

C'est bien « un calcul qu'on ne fait qu'une fois par import » : par **(département, classe
DPE)**, on estime `τ̂_c²` une seule fois. Déterministe (sommes commutatives → INV-5). ✅

**Cas limites** (tests) :
- `Σw_m(β̂−μ)² ≤ M−1` ⇒ `τ̂²=0` ⇒ pooling complet ;
- `v_m = ∞` (commune sans dof résiduel, §6) ⇒ `w_m=0` : ne pèse pas sur `τ̂²`, et sa propre
  estimation est **entièrement** tirée vers `μ̂` (« aucune info → prior ») ;
- `M = 1` ⇒ `τ̂²` non estimable ⇒ **repli : pas de shrinkage** (garde `β̂` local) ;
- classe **référence D** : effet 0 par construction ⇒ **jamais shrinkée** (ancre).

---

## 5. Artefacts intermédiaires & persistance (le cœur de la livraison)

L'observation clé : **matière première ≠ agrégats dérivés**. On sépare ce qui se
**persiste tôt** de ce qui se **recalcule** — A3 devient un **pipeline reprenable**, pas un
batch monolithique en mémoire.

**Déjà persisté** (`coefficient_maille_historique`, migration 010) : `coef_dpe` = **β̂** ✓,
`effectifs_dpe` = **n_c** ✓. **Manque** : `se_dpe` = **`v = SE²`** — *le seul vrai
prérequis*.

| Artefact | Contenu | Où / quand | Nature |
|---|---|---|---|
| **A — `se_dpe`** | variance par classe du fit local | dans `regression.fit` puis **persisté** | **lot 1 standalone**, utile même sans A3 (IC honnête, diagnostic admin #45) |
| **B — fit local brut** | `(β̂, se_dpe, effectifs)` de **toutes** les communes non-REFUS | **staging** avant la décision de cascade | nécessaire car DL utilise le LOCAL brut, même des communes finalement servies par EPCI/DÉPT |
| **C — priors** `τ̂_c²` (+ `μ̂_c`) | par (dept, millésime, classe) | **agrégat** sur B | pur / **recalculable** ; persistable dans une petite table `prior_dpe` (expose aussi `τ̂²` à l'admin, §8.6) |
| **D — coef shrinké** | `θ̂` appliqué puis cascade | passe finale | ce qui alimente `coefficient_maille_historique` publié |

Conséquences :
- **`se_dpe` (A) se livre en premier, isolément** — valeur immédiate, risque minimal ;
- **`μ̂_c` (le fit poolé, §3) est déjà préparé** par le cran départemental — pas
  d'agrégation à attendre pour la cible ;
- **`τ̂²` (C) est un agrégat léger** sur l'archive `(β̂, SE)`, **recalculable** (voire
  rétro-appliquable aux millésimes déjà archivés une fois `se_dpe` présent) ;
- la **parallélisation par département** est préservée (chaque dept produit ses fits+SE ;
  priors et shrinkage sont des étapes par-dept).

---

## 6. Prérequis technique — exposer `se_dpe` depuis le fit

Le fit résout les MCO pondérés sur le **design blanchi** `Xw = design·√w`, `yw = y·√w`
(`_fit_model`). Il jette la covariance ; il faut la **conserver** :

```
résidus rw = yw − Xw·β̂
σ̂²  = (rwᵀrw) / (n − p)          p = nb de colonnes du design
SE_j = √( σ̂² · diag( pinv(XwᵀXw) )_j )
```

- **~6-8 lignes** dans `_fit_model`/`fit` + champ `RegressionResult.se_dpe: dict[str,
  float]`. Extraction **triviale** : DPE déjà aux positions `dpe_debut + offset` de `beta`.
- **Robustesse** : `pinv` (pas `inv`) — `XwᵀXw` quasi-singulière en petit échantillon.
  **Garde dof** : `n − p ≤ 0` ⇒ `SE = +∞`. Cohérent avec A3 : `v=∞ ⇒ w=0 ⇒` tiré vers `μ̂`.
  A3 **s'auto-régularise** en petit-n (contrairement au count-based et son `k` à deviner).

**Bonus** : les SE ouvrent un **IC honnête** — `Var(θ̂_m) ≈ τ̂²·v_m/(τ̂²+v_m)` remplacerait
l'heuristique S29 de `ic_dpe`. Évolution (§8, D-H).

---

## 7. Pipeline — pré-calcul, staging, agrégat, shrinkage

`orchestration.compute_departement` (aujourd'hui une passe paresseuse) devient :

1. **Cible parente d'abord** — calculer le cran **départemental poolé** (`μ̂_c`), déjà
   mémoïsé #175. Disponible d'entrée.
2. **Fits locaux + SE (staging B)** — pour chaque commune : fit LOCAL → `(β̂, se_dpe,
   effectifs)`. Collecter par classe. *Pas encore de publication.*
3. **Priors (C)** — par classe présente : `τ̂_c²` (MoM autour de `μ̂_c`, §4).
4. **Shrinkage + décision (D)** — appliquer `θ̂` à chaque commune, reconstruire la
   `MailleCoefficient`, **puis** invariants + cascade **comme aujourd'hui** (LOCAL shrinké
   qui passe désormais publie ; sinon EPCI → DÉPT inchangé).

Le **coût lourd** (les fits) est en (2). Le shrinkage est un **régulariseur en amont** de
la cascade : tirer une petite maille vers l'effet départemental **améliore** sa monotonie
(INV-1) et sa vraisemblance (INV-2) ⇒ **moins de bascules**. La cascade reste le filet.
INV-5 : `μ̂` et `τ̂²` déterministes, mélange affine ⇒ reproductible. ✅

**Caveat erreur/palier** : l'erreur médiane (CV) reste mesurée sur le **fit local**, pas
sur l'estimateur shrinké (re-mesurer exigerait le shrinkage par pli, lourd). Assumé,
**vérifié en calibration** (l'erreur ne doit pas se dégrader).

---

## 8. Config, activation, sortie

- `CalculConfig.pooling_hierarchique: bool = False` (`VV_MOTEUR_POOLING_HIERARCHIQUE`).
  **`False` = désactivé = non-régression exacte** (défaut), comme τ #208. **Aucune
  constante à régler** — A3 est non-paramétrique.
- `se_dpe` **persisté** (lot 1) : colonne JSONB sur `coefficient_maille_historique`
  (comme `effectifs_dpe`/`ic_dpe`). Staging `fit_local` + table `prior_dpe` : à cadrer au
  câblage (peut être en mémoire d'abord, persisté ensuite si le besoin de reprise se
  confirme).
- `coef_dpe` publié porte déjà `θ̂` ; `mode` reste `LOCAL` ; fiabilité (n, rayon) inchangée.

---

## 9. Protocole de validation (VPS, comme #177/#208)

Panel **denses + ruraux** (≈ 6-15 dépts, N=2024), `pooling_hierarchique` **off vs on** :

1. **Stabilité rurale** — variance/queue **p90** du premium E→C entre mailles ; écart
   inter-millésime REG-1 (doit ↓ sur les petites mailles).
2. **Biais / atténuation** — premium des mailles **à forte précision** quasi inchangé
   (garde-fou : ne pas moyenniser une commune réellement plus verte). Garanti par
   construction (`w→1`), à vérifier.
3. **Taux de publication au LOCAL** — ↑ (gain principal).
4. **Erreur médiane CV** — ne pas se dégrader (idéalement ↓).
5. **Monotonie INV-1** — taux de passage ↑.
6. **Diagnostic** — distribution des `τ̂_c²` par classe (pooling fort vs faible) ; cohérence
   métier.

**Décision de sortie** : basculer le défaut à `on` **si** (1)+(3)+(5) nets **sans** coût
sur (2)+(4) ; sinon garder opt-in (comme τ #208).

---

## 10. Découpage d'implémentation (après validation)

1. **Lot 1 — `se_dpe` (pur + persistance, standalone).** `regression.fit` conserve la
   covariance ⇒ `se_dpe` (pinv + garde dof ⇒ `∞`) ; persisté sur
   `coefficient_maille_historique` (migration + upsert + lecteur). Tests : petit OLS
   pondéré à SE connu, dof≤0 ⇒ `∞`, quasi-singularité (pinv ne casse pas). **Livrable seul**
   (IC/diagnostic).
2. **Lot 2 — `τ̂²` empirical-Bayes (pur).** Module `pooling.py` : `variance_inter(β̂, v, μ)`
   (MoM, §4) et `shrink(β̂, μ, τ², v)` (+ `variance_posterieure`). Tests : `τ²=0` (pooling
   complet), `τ²` grand (pas de pooling), `M=1` (repli), `v=∞` (w=0 → μ), déterminisme.
3. **Lot 3 — câblage pipeline.** `compute_departement` : cible poolée → fits+SE (staging) →
   priors par classe → shrinkage+cascade ; config `pooling_hierarchique` ; référence D non
   tirée ; bandes. `MOTEUR-REGLES-CALCUL.md` (§4/§4ter/§7/§13).
4. **Lot 4 — calibration VPS** (§9) — `docs/lab/resultats-pooling-partiel.md`, décision
   défaut vs opt-in.
5. **(Évolutions)** IC a posteriori (remplace S29) ; méta-moyenne `μ̂` (EB pur) si la
   calibration le justifie ; persistance `prior_dpe`/staging si reprise nécessaire.

---

## 11. Risques et points ouverts

- **Cible mixte** : `μ̂` = fit poolé (régression sur toutes les ventes) tandis que `τ̂²`
  mesure la dispersion des régressions **communales** — deux estimateurs mêlés. Défendable
  (le poolé est un excellent proxy de l'effet départemental) et **naturellement préparé** ;
  la méta-moyenne (EB pur) est le raffinement si besoin.
- **Fiabilité des SE en petit dof** : `SE` bruité quand `n−p` petit. A3 l'absorbe (`v` grand
  ⇒ `w→0`), mais un `SE` sous-estimé sur-pondérerait une maille. Calibration (2).
- **`τ̂²` instable en petit dept / classe rare** (`M` faible) : garde `M=1` ⇒ pas de
  shrinkage ; envisager un plancher `M ≥ M_min` (à trancher en calibration).
- **Double régularisation** : A3 réduit **par construction** l'écart inter-millésime (comme
  la fenêtre) ⇒ REG-1 encore plus muet — à ne pas confondre avec de la stabilité de marché.
- **Erreur/palier sur le fit local** (§7) — caveat assumé, vérifié en calibration.

---

## 12. Décisions (validées / à valider)

| Réf | Décision |
|---|---|
| **D-A** ✅ | Pooling **hiérarchique empirical-Bayes (A3)** — poids `w = τ²/(τ²+SE²)` piloté par les données, **sans constante `k`** |
| **D-B** ✅ | `τ̂²` en **forme fermée** (méthode des moments / DL à centre fixé) par (dept, classe) |
| **D-C** ✅ | Exposer **`se_dpe`** depuis le fit (covariance MCO, `pinv` + garde dof ⇒ `∞`) |
| **D-D** ✅ | Tirer **les effets DPE** seuls (pas les élasticités) ; référence D jamais tirée |
| **D-E** ✅ | Cible `μ̂` = **fit départemental poolé** (déjà mémoïsé, préparé en amont) ; méta-moyenne = raffinement |
| **D-F** ✅ | **Pipeline persistant reprenable** : `se_dpe` persisté (lot 1 standalone) → staging fits bruts → agrégat priors → shrinkage+cascade ; shrinkage **avant** invariants |
| **D-G** ✅ | **Opt-in** `pooling_hierarchique=False` par défaut ; seule persistance nouvelle = `se_dpe` (lot 1) |
| **D-H** | IC a posteriori (remplace S29) + méta-moyenne EB = **évolutions** séparées, après validation |

Prochaine étape : **lot 1 = `se_dpe` dans `regression.fit` + persistance**, en TDD.
