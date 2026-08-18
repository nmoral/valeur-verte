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
régulariser chaque petite maille vers la distribution départementale de l'effet DPE,
**proportionnellement à sa propre précision**.

---

## 2. Le modèle hiérarchique (A3)

Pour une classe DPE `c` fixée, chaque commune `m` d'un département a un **vrai effet**
`θ_{m,c}` (l'effet log de la classe `c` vs la référence D). Le fit local en donne une
estimation `β̂_{m,c}` de **variance d'échantillonnage** `v_{m,c} = SE_{m,c}²`. On pose
que les communes du département partagent une **loi commune** de l'effet :

```
β̂_{m,c} | θ_{m,c} ~ Normal(θ_{m,c}, v_{m,c})      (bruit d'estimation, connu)
θ_{m,c}           ~ Normal(μ_c, τ_c²)             (dispersion inter-communes)
```

- `μ_c` = **effet départemental moyen** de la classe `c` ;
- `τ_c²` = **variance inter-communes** de l'effet — *combien les communes diffèrent
  vraiment*, une fois retiré le bruit d'estimation.

L'estimateur de shrinkage (moyenne a posteriori empirical-Bayes) est un **mélange
précision-pondéré** :

```
θ̂_{m,c} = μ̂_c + w_{m,c} · (β̂_{m,c} − μ̂_c)
w_{m,c} = τ̂_c² / (τ̂_c² + v_{m,c})
```

- classe **bien estimée** dans `m` (`v` petit devant `τ²`) ⇒ `w → 1` ⇒ **quasi inchangée** ;
- classe **mal estimée** (`v` grand : peu de ventes, peu de dof) ⇒ `w → 0` ⇒ **tirée vers
  `μ̂_c`** ;
- si les communes **s'accordent** (`τ̂_c² → 0`) ⇒ `w → 0` pour toutes ⇒ **pooling complet**
  (l'effet de la classe est homogène dans le département, on sert `μ̂_c` partout) ;
- si elles **divergent réellement** (`τ̂_c²` grand) ⇒ `w → 1` ⇒ **pas de pooling** (le signal
  local est réel, on le respecte).

**La grande propriété** : `w` est **entièrement piloté par les données** `(v, τ²)`. Aucune
constante `k` arbitraire à régler — c'est le modèle qui décide combien tirer. La
calibration ne règle plus un curseur, elle **valide** le résultat.

---

## 3. Estimation fermée — DerSimonian-Laird

`μ_c` et `τ_c²` s'estiment en **forme fermée** (méthode des moments, une passe — pas de
MCMC, pas d'optimisation itérative), sur l'ensemble des estimations communales
`{(β̂_{m,c}, v_{m,c})}` du département. C'est la **méta-analyse à effets aléatoires**
standard (DerSimonian-Laird 1986) :

```
w_i   = 1 / v_i                       (poids à effets fixes)
ȳ     = Σ w_i β̂_i / Σ w_i
Q     = Σ w_i (β̂_i − ȳ)²             (hétérogénéité observée)
C     = Σ w_i − (Σ w_i²) / (Σ w_i)
τ̂²    = max(0, (Q − (M−1)) / C)       (variance inter-communes, plancher 0)
w_i*  = 1 / (v_i + τ̂²)                (poids à effets aléatoires)
μ̂     = Σ w_i* β̂_i / Σ w_i*
```

puis le shrinkage `θ̂_m = μ̂ + τ̂²/(τ̂²+v_m)·(β̂_m − μ̂)`.

C'est **exactement** « un calcul qu'on ne fait qu'une fois par import » : par
**(département, classe DPE)**, on estime `(μ̂_c, τ̂_c²)` une seule fois, puis on applique le
mélange à chaque commune. Déterministe (sommes commutatives → indépendant de l'ordre,
INV-5). ✅

**Cas limites** (à couvrir en tests) :
- `Q ≤ M−1` ⇒ `τ̂² = 0` ⇒ pooling complet (communes cohérentes au bruit près) ;
- `v_m = ∞` (commune sans dof résiduel, §4) ⇒ `w_i = 0` : ne pèse pas sur `μ̂`, et sa propre
  estimation est **entièrement** tirée vers `μ̂` (comportement voulu — « aucune info, on
  fait confiance au prior ») ;
- `M = 1` (une seule commune porte la classe `c` dans le département) ⇒ `τ̂²` indéfini ⇒
  **repli : pas de shrinkage** (on garde `β̂` local ; il n'y a pas de population à laquelle
  se comparer) ;
- classe = **référence D** : effet 0 par construction dans toute maille ⇒ **jamais
  shrinkée** (c'est l'ancre). On ne mélange que les classes non-référence.

---

## 4. Prérequis — exposer les écarts-types (`se_dpe`)

Le fit résout les MCO pondérés sur le **design blanchi** `Xw = design·√w`, `yw = y·√w`
(`_fit_model`). Il jette la covariance ; il faut la **conserver** :

```
résidus rw = yw − Xw·β̂
σ̂²  = (rwᵀrw) / (n − p)          p = nb de colonnes du design
SE_j = √( σ̂² · diag( pinv(XwᵀXw) )_j )
```

- **~6-8 lignes** dans `_fit_model`/`fit` + un champ `RegressionResult.se_dpe: dict[str,
  float]`. Extraction **triviale** : les DPE sont déjà à des positions connues de `beta`
  (`dpe_debut + offset`, mêmes indices que `coef_dpe`).
- **Robustesse** : `pinv` (pas `inv`) car `XwᵀXw` peut être **quasi-singulière** en petit
  échantillon (colinéarité, classes rares). **Garde dof** : `n − p ≤ 0` ⇒ `SE = +∞` (info
  nulle). C'est **cohérent** avec A3 : une maille sans dof donne `v = ∞` ⇒ `w = 0` ⇒ tirée
  vers `μ̂`. A3 **s'auto-régularise** dans le régime petit-n (contrairement au count-based,
  qui devait deviner un `k`).

**Bonus** — les SE ouvrent un **IC honnête** : la variance a posteriori
`Var(θ̂_m) ≈ τ̂²·v_m/(τ̂²+v_m)` (+ terme d'incertitude sur `μ̂`) remplacerait l'heuristique
S29 (`coef ± |coef|·erreur/100`) de `ic_dpe`. À considérer en évolution (§9).

---

## 5. Ce qu'on tire, et vers quel parent

- **On shrink les effets DPE** (`coef_dpe`) — le **signal publié** (comme REG-1 #176). La
  référence D reste l'ancre à 0.
- **On ne shrink PAS les élasticités** `coef_m2_bati/terrain` — régresseurs internes,
  déjà instables et **plafonnés en alerte** par REG-1 #176. (Extension possible : un
  pooling hiérarchique séparé des élasticités, hors périmètre.)
- **Groupe hiérarchique = le département** : c'est le périmètre stable et universel
  (l'EPCI n'est pas toujours défini), cohérent avec la cible de la cascade #169. `μ̂_c`
  est la **moyenne à effets aléatoires** des estimations communales — *pas* le fit
  départemental poolé (`compute_maille_departement`), qui est un estimateur différent
  (régression sur toutes les ventes vs méta-moyenne des régressions communales). Le fit
  poolé reste disponible comme diagnostic / repli.

---

## 6. Pipeline — batch en deux passes

A3 impose une **structure à deux passes** par département (aujourd'hui
`orchestration.compute_departement` itère en une passe et cascade paresseusement) :

1. **Passe 1 — fits locaux.** Pour chaque commune, calculer le fit **LOCAL** (sélection
   adaptative → fit → `coef_dpe` **+ `se_dpe`** + effectifs). *Ne pas encore publier.*
   Collecter, par classe `c`, l'ensemble `{(β̂_{m,c}, v_{m,c})}` du département.
2. **Inter-passes — priors.** Par classe `c` présente dans le département : DerSimonian-
   Laird ⇒ `(μ̂_c, τ̂_c²)`. Une seule fois par (département, classe).
3. **Passe 2 — shrinkage + décision.** Pour chaque commune : appliquer le mélange à ses
   effets DPE, reconstruire la `MailleCoefficient`, **puis** dérouler invariants + cascade
   **comme aujourd'hui** — une maille LOCAL *shrinkée* qui passe désormais les invariants
   publie au LOCAL ; sinon repli EPCI → DÉPARTEMENT inchangé.

Le **coût lourd** (les fits) est en passe 1 uniquement ; la passe 2 réutilise. Les crans
EPCI/DÉPT restent mémoïsés (#175). Mémoire : ~600 communes × ~6 classes de `(β̂, SE)` par
département = quelques milliers de flottants, négligeable.

**Le shrinkage est un régulariseur *en amont* de la cascade**, pas un remplaçant : tirer
une petite maille vers la loi départementale **améliore** sa monotonie (INV-1) et sa
vraisemblance (INV-2) ⇒ **moins de bascules**. La cascade reste le filet de sécurité.

---

## 7. Config, activation, sortie

- Nouveau `CalculConfig.pooling_hierarchique: bool = False`
  (`VV_MOTEUR_POOLING_HIERARCHIQUE`). **`False` = désactivé = non-régression exacte**
  (défaut), comme `decroissance_temporelle_tau` (#208). **Aucune constante à régler** —
  A3 est non-paramétrique (les poids viennent de `v, τ²`).
- **Aucune migration ni colonne nouvelle** : `coef_dpe` porte déjà les valeurs publiées.
  Le `mode` reste `LOCAL` (estimation locale régularisée, pas un repli) ; `rayon_retenu`
  et la fiabilité (n, rayon) inchangés. `se_dpe` reste **interne** au calcul.
- **Caveat erreur/palier** : l'erreur médiane (CV) est mesurée sur le **fit local**, pas
  sur l'estimateur shrinké (re-mesurer exigerait le shrinkage dans chaque pli, lourd).
  Assumé, **vérifié en calibration** (l'erreur ne doit pas se dégrader).

---

## 8. Protocole de validation (VPS, comme #177/#208)

Sur un panel **denses + ruraux** (≈ 6-15 départements, N=2024), comparer
`pooling_hierarchique` **off vs on** (pas de grille de `k` : A3 n'en a pas) sur :

1. **Stabilité rurale** — variance et queue **p90** du premium E→C entre mailles d'un même
   département ; **écart inter-millésime REG-1** (doit ↓). *Attendu : ↓ sur les petites
   mailles.*
2. **Biais / atténuation** — le premium des mailles **à forte précision** (`v` petit) doit
   rester **quasi inchangé** (garde-fou : ne pas moyenniser une commune réellement plus
   verte). *A3 le garantit par construction (`w→1`), à vérifier empiriquement.*
3. **Taux de publication au LOCAL** — fraction de communes publiant au LOCAL au lieu de
   tomber en EPCI/DÉPT (**gain principal attendu : ↑**).
4. **Erreur médiane CV** — ne doit pas se dégrader (idéalement ↓).
5. **Monotonie INV-1** — taux de passage (doit ↑).
6. **Diagnostic hiérarchique** — distribution des `τ̂_c²` par classe : petites (pooling
   fort) vs grandes (peu de pooling) ; cohérence avec l'intuition métier (le premium des
   passoires F/G est-il homogène ou hétérogène dans un département ?).

**Décision de sortie** : basculer le défaut à `on` **si** (1)+(3)+(5) nets **sans** coût
sur (2)+(4) ; sinon garder opt-in (comme τ #208).

---

## 9. Découpage d'implémentation (après validation)

Lots TDD (logique pure d'abord — modèle DAT/#208) :

1. **Pur — écarts-types.** `regression.fit` conserve la covariance ⇒ `se_dpe` sur
   `RegressionResult` (pinv + garde dof ⇒ `+∞`). Tests : petit OLS pondéré à SE connu,
   dof≤0 ⇒ `∞`, quasi-singularité (pinv ne casse pas).
2. **Pur — empirical Bayes.** Module `pooling.py` : `dersimonian_laird(estimations) →
   (mu, tau2)` et `shrink(beta, mu, tau2, v) → theta` (+ `variance_posterieure`). Tests :
   `τ²=0` (homogène → pooling complet), `τ²` grand (pas de pooling), `M=1` (repli), `v=∞`
   (poids 0 → tiré à `μ`), déterminisme (ordre).
3. **Câblage — deux passes.** Restructurer `compute_departement` (passe 1 fits+SE → priors
   par classe → passe 2 shrinkage+cascade) ; config `pooling_hierarchique` ; référence D
   non tirée. Mise à jour `MOTEUR-REGLES-CALCUL.md` (§4/§4ter/§7/§13).
4. **Calibration VPS** (§8) — `docs/lab/resultats-pooling-partiel.md`, décision défaut vs
   opt-in.
5. **(Évolution) IC a posteriori** — remplacer l'heuristique S29 par
   `Var(θ̂) = τ̂²v/(τ̂²+v)`. Lot séparé, une fois A3 validé.

---

## 10. Risques et points ouverts

- **Deux passes** = restructuration de `compute_departement` (aujourd'hui une passe) et
  mémoire des fits/SE en passe 1 (négligeable, §6). Principal poste d'effort.
- **Fiabilité des SE en petit dof** : `SE` est lui-même bruité quand `n−p` est petit. A3
  l'absorbe (`v` grand ⇒ `w→0`), mais un `SE` sous-estimé par malchance sur-pondèrerait une
  maille. La calibration (2) le contrôle.
- **`τ̂²` instable en petit département / classe rare** (`M` faible) : DL est bruité. Garde :
  `M=1` ⇒ pas de shrinkage ; envisager un plancher `M ≥ M_min` sous lequel on garde le
  local (à trancher en calibration).
- **Double régularisation** : A3 réduit **par construction** l'écart inter-millésime (comme
  la fenêtre) ⇒ REG-1 devient encore plus muet — à ne pas confondre avec de la vraie
  stabilité de marché lors de l'interprétation.
- **μ̂ = méta-moyenne** (pas fit poolé) : choix assumé (cohérence hiérarchique) ; le fit
  poolé départemental reste un diagnostic.
- **Erreur/palier sur le fit local** (§7) — caveat assumé, vérifié en calibration.

---

## 11. Décisions à valider (récap)

| Réf | Décision proposée |
|---|---|
| **D-A** | **Pooling hiérarchique empirical-Bayes (A3)** — poids `w = τ²/(τ²+SE²)` piloté par les données, **sans constante `k`** |
| **D-B** | Estimation **fermée DerSimonian-Laird** par (département, classe DPE) — une passe, déterministe |
| **D-C** | Exposer **`se_dpe`** depuis le fit (covariance MCO pondérés, `pinv` + garde dof ⇒ `∞`) |
| **D-D** | On tire **les effets DPE** seulement (pas les élasticités) ; référence D jamais tirée |
| **D-E** | Groupe = **département** ; `μ̂` = **méta-moyenne à effets aléatoires** (pas le fit poolé) |
| **D-F** | Batch en **deux passes** ; shrinkage **avant** invariants/cascade (régulariseur amont) |
| **D-G** | **Opt-in** `pooling_hierarchique=False` par défaut ; aucune persistance nouvelle |
| **D-H** | IC a posteriori (remplacer S29) = **évolution** séparée, après validation |

Une fois ces points tranchés, on ouvre l'issue GitHub dédiée et on part sur le lot 1
(SE dans `regression.fit`) en TDD.
