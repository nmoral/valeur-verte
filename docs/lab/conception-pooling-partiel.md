# Note de conception — Pooling partiel (shrinkage) des coefficients DPE

> **Statut : proposition, à valider avant tout code.** Cette note fixe le *quoi* et le
> *comment* du pooling partiel ; elle ne modifie pas encore le moteur. Une fois validée,
> elle guide l'implémentation TDD (opt-in, défaut désactivé) puis la calibration VPS,
> sur le modèle de la fenêtre glissante (#177) et de la pondération temporelle (#208).

Reste explicite de la décision #115 (« tirer un coefficient communal à faible effectif
vers le départemental ») et point ouvert de [`RECAP.md`](RECAP.md) §6.

---

## 1. Le problème

Le moteur estime les effets DPE **par commune** (cran LOCAL, `regression.fit`). En
**rural**, une classe DPE peut n'avoir que 20-30 ventes : l'effet estimé est **bruité**
(forte variance de rééchantillonnage). Deux symptômes concrets déjà observés :

- **inversions de monotonie de bruit** (INV-1) sur classes rares — le premium E→C d'une
  petite maille peut être aberrant par simple aléa de tirage ;
- **cascade #169 brutale** : une commune dont le cran LOCAL rate un invariant bascule
  **entièrement** au cran EPCI puis DÉPARTEMENT — une estimation **uniforme, identique
  pour toutes les communes du périmètre** (#175). Le passage est **discontinu** :
  `n_local = 32` (juste au-dessus du seuil 30) publie un fit local très bruité ;
  `n_local = 28` reçoit l'estimation de tout l'EPCI. Rien entre les deux.

La fenêtre glissante (#177) et la pondération temporelle (#208) réduisent déjà la
variance **dans le temps** (empiler des millésimes). Le pooling partiel s'attaque à la
variance **dans l'espace/l'effectif** : régulariser une petite maille vers un **parent
plus stable**, proportionnellement à ce qu'on sait d'elle.

---

## 2. L'idée : partial pooling, count-based

Pour chaque classe DPE `c` d'une maille LOCAL, publier un **mélange** entre l'effet
**local** et l'effet du **parent** (départemental), pondéré par l'effectif local de la
classe :

```
coef_publié[c] = w_c · coef_local[c] + (1 − w_c) · coef_parent[c]
w_c = n_c / (n_c + k)
```

- `n_c` = effectif de la classe `c` dans l'échantillon local (déjà calculé,
  `_effectifs_dpe`) ;
- `k` = **constante de shrinkage** (config). `k = 0` ⇒ `w_c = 1` ⇒ **pas de shrinkage**
  (non-régression exacte). `k` grand ⇒ shrinkage fort. À `n_c = k`, le poids vaut ½
  (autant de local que de parent) ;
- classe très fournie (`n_c ≫ k`) ⇒ `w_c → 1` ⇒ **quasi inchangée** ; classe rare
  (`n_c` petit) ⇒ tirée vers le parent.

C'est du **James-Stein / empirical-Bayes count-based**. On ne fait **pas** d'empirical
Bayes *variance-based* (poids = précision = 1/SE²) car `regression.RegressionResult`
**n'expose pas** les écarts-types des coefficients (seulement les estimations
ponctuelles, `n`, `r²`). L'effectif par classe est le **proxy de précision** disponible ;
c'est plus grossier mais **déterministe, lisible et défendable** (voir §11, risque
« proxy »).

**Décision à valider (D-A)** : count-based (effectif) plutôt que variance-based (SE non
disponibles). Alternative plus lourde = faire remonter les SE de l'OLS pondéré par
`regression.fit` — possible mais hors périmètre de cette première itération.

---

## 3. Ce qu'on tire (et ce qu'on ne tire pas)

- **On shrink les effets DPE** (`coef_dpe`, base D=0) — c'est **le signal publié** (comme
  REG-1 #176 qui ne juge la dérive que sur les effets DPE). La référence D reste 0 (on
  mélange des effets **relatifs**).
- **On ne shrink PAS les élasticités** `coef_m2_bati` / `coef_m2_terrain` — régresseurs
  internes invisibles de l'utilisateur, déjà instables et **plafonnés en alerte** (jamais
  bloquants) par REG-1 #176. Les tirer n'améliore pas le signal produit et brouillerait
  la lecture.

**Décision à valider (D-B)** : shrinkage limité aux effets DPE.

---

## 4. Vers quel parent

Cible par défaut = **le cran DÉPARTEMENTAL** (`compute_maille_departement`), parce que :

- il est **toujours défini** (parent universel), contrairement à l'EPCI (communes
  isolées hors EPCI) ;
- il est **déjà calculé et mémoïsé une fois par batch** (#175, `maille_departementale`) —
  coût marginal nul ;
- il est **le plus stable** (tout le département, σ uniforme).

**Subtilité — bandes.** Le parent départemental fusionne des classes DPE adjacentes en
**bandes** (#169, ex. `{"F":"F","G":"F"}`). Pour tirer la classe locale `c` vers le
parent, on prend l'effet du **représentant de bande** de `c` côté parent
(`MailleCoefficient.bandes` + `coef_dpe`). Si `c` n'a **aucun** représentant côté parent
(cas très rare), la classe **n'est pas shrinkée** (`w_c = 1`, repli sûr).

**Décision à valider (D-C)** : cible = départemental (EPCI comme évolution future). Un
paramètre `pooling_cible` pourra ouvrir l'EPCI plus tard.

---

## 5. Où ça se branche dans le pipeline

Le shrinkage s'applique **au seul cran LOCAL** — les crans EPCI/DÉPARTEMENT **sont** le
parent, on ne les tire pas. Chaîne actuelle (`_compute_from_sample`, §1 du référentiel) :

```
sélection → écrêtage → gate classes publiables → fit → CV (erreur) → palier → maille
```

Le shrinkage s'insère **après `fit`, avant les invariants/publication** :

```
… → fit → [SHRINKAGE des coef_dpe vers le parent] → CV → palier → maille → invariants
```

**Contrainte d'ordre** : il faut le parent départemental **avant** de calculer chaque
maille LOCAL. Aujourd'hui `orchestration.compute_departement` calcule le départemental
**paresseusement** (au premier repli). Réordonnancement proposé : **pré-calculer le
parent départemental une fois** en tête de la boucle communes, puis le passer à
`compute_maille` (nouveau paramètre optionnel `parent: MailleCoefficient | None`). Le
parent reste mémoïsé/réutilisé — pas de surcoût. Le mode DÉPARTEMENTAL interne
(`_maille_departementale`, #200) fournit un parent **sans bandes** ; on privilégiera le
parent **avec bandes** de `compute_maille_departement` pour couvrir toutes les classes.

**Décision à valider (D-D)** : pré-calcul du parent départemental en tête de boucle,
injecté dans le cran LOCAL.

---

## 6. Interaction avec les invariants et la cascade

- **INV-1 (monotonie) / INV-2 (signe & ordre)** sont évalués **après** shrinkage. C'est
  **voulu et bénéfique** : tirer une petite maille vers un parent (monotone, plausible)
  **améliore** sa monotonie et sa vraisemblance → **moins de bascules** de cascade. Le
  pooling partiel est un **régulariseur en amont** de la cascade, pas un remplaçant.
- **La cascade #169 reste le filet** : si même la maille LOCAL *shrinkée* rate un
  invariant, on descend EPCI → DÉPARTEMENT comme aujourd'hui. Aucun comportement retiré.
- **INV-5 (reproductibilité)** : le parent est déterministe (barycentre, ordre canonique
  #175) ; le mélange est une combinaison affine déterministe. ✅ Reproductible.

---

## 7. Erreur médiane (palier) et IC — deux caveats assumés

- **Erreur médiane (CV, `median_relative_error`)** : mesurée sur le **fit local**, pas
  sur l'estimateur *shrinké*. La rigueur voudrait re-mesurer l'erreur de l'estimateur
  publié — mais la CV re-fit par pli, et appliquer le shrinkage **dans chaque pli**
  exigerait le parent par pli (lourd, et le parent lui-même varie peu). **Choix
  pragmatique** : garder l'erreur du fit local ; le shrinkage **réduit** la variance, il
  ne dégrade pas matériellement l'erreur prédictive. **La calibration (§9) vérifie** que
  l'erreur CV ne se dégrade pas (idéalement elle s'améliore).
- **IC (`ic_dpe`)** : heuristique S29 (`coef ± |coef|·erreur/100`). Il suit
  mécaniquement le coef shrinké — acceptable pour cette itération. Un IC qui refléterait
  la réduction de variance du shrinkage est un raffinement futur.

**Décision à valider (D-E)** : erreur/palier mesurés sur le fit local (caveat assumé,
vérifié en calibration).

---

## 8. Config, activation, sortie

- Nouveau paramètre `CalculConfig.pooling_partiel_k: float | None = None`
  (`VV_MOTEUR_POOLING_PARTIEL_K`). **`None` = désactivé = non-régression exacte** (défaut),
  exactement comme `decroissance_temporelle_tau` (#208). `k > 0` active.
- **Aucune migration ni colonne nouvelle** : `coef_dpe` porte déjà les valeurs publiées.
  Le `mode` reste `LOCAL` (c'est une estimation locale régularisée, pas un repli), le
  `rayon_retenu` et la fiabilité Solide/Correct/Fragile (n, rayon) sont inchangés.
- **Faut-il marquer une maille shrinkée ?** Optionnel. Pour le MVP, non : le coef publié
  *est* la valeur. Un futur marqueur (`alerte`/champ dédié) pourra tracer l'ampleur du
  shrinkage pour l'admin (#45). Noté comme évolution.

**Décision à valider (D-F)** : opt-in `pooling_partiel_k=None` par défaut, pas de
persistance nouvelle.

---

## 9. Protocole de validation (VPS, comme #177/#208)

Sur un panel **denses + ruraux** (≈ 6-15 départements, N=2024), comparer
`k ∈ {0, 10, 20, 40}` (0 = référence) sur :

1. **Stabilité rurale** — variance et queue **p90** du premium E→C entre mailles d'un
   même département ; **écart inter-millésime REG-1** (doit baisser). *Attendu : ↓ sur les
   petites mailles.*
2. **Biais / atténuation** — le premium des mailles **à fort effectif** doit rester
   quasi inchangé (garde-fou : le shrinkage ne doit pas « moyenniser » une commune
   réellement plus verte). *Mesure : premium à `n_c` élevé vs faible.*
3. **Taux de publication au LOCAL** — fraction de communes qui publient désormais au
   cran LOCAL au lieu de tomber en EPCI/DÉPT. *C'est le gain principal attendu : ↑.*
4. **Erreur médiane CV** — ne doit pas se dégrader (idéalement ↓).
5. **Monotonie INV-1** — taux de passage (doit ↑).

**Décision de sortie** : soit fixer un `k` par défaut, soit **garder opt-in** — comme
τ (#208), qui est resté opt-in parce qu'il ajoutait plus de retard qu'il n'en corrigeait.
On ne bascule le défaut **que si** le gain (3) et (1) est net **sans** coût sur (2) et (4).

---

## 10. Découpage d'implémentation (après validation de cette note)

Lots TDD envisagés (logique pure d'abord, câblage ensuite — modèle DAT) :

1. **Pur** — `pooling.py` : `poids_shrinkage(n_c, k)` et `melanger_effets_dpe(local,
   parent, effectifs, bandes, *, k)` → dict d'effets shrinkés. Tests : `k=0` neutre,
   classe fournie ≈ locale, classe rare ≈ parent, classe sans parent non tirée, bandes.
   Config `pooling_partiel_k`.
2. **Câblage** — pré-calcul du parent départemental dans `orchestration.compute_departement`,
   injection dans `compute_maille` (cran LOCAL) via `_compute_from_sample`, application du
   mélange avant `_maille`. Mise à jour `MOTEUR-REGLES-CALCUL.md` (§4/§7/§13).
3. **Calibration VPS** (§9) — note de résultats `docs/lab/resultats-pooling-partiel.md`,
   décision défaut vs opt-in.

---

## 11. Risques et points ouverts

- **Sur-shrinkage** : un `k` trop grand tire toutes les petites mailles vers la moyenne
  départementale → perte du signal local réel (une commune vraiment plus verte paraît
  moyenne). *Mitigation :* poids count-based (les classes fournies restent locales), `k`
  réglable, calibration (2).
- **Proxy count-based** : l'effectif n'est pas la précision (une classe à 25 ventes peut
  avoir une forte variance résiduelle). *Assumé* pour cette itération ; les SE de l'OLS
  seraient le raffinement.
- **Double régularisation** : le shrinkage réduit **par construction** l'écart
  inter-millésime (comme la fenêtre glissante). REG-1 deviendra **encore plus muet** — à
  ne pas confondre avec de la vraie stabilité de marché lors de l'interprétation.
- **Bandes du parent** : mapping classe locale → effet de bande du parent ; classe sans
  représentant → non shrinkée (repli sûr).
- **Erreur/palier sur le fit local** (§7) — caveat assumé, vérifié en calibration.

---

## 12. Décisions à valider (récap)

| Réf | Décision proposée |
|---|---|
| **D-A** | Shrinkage **count-based** (`w = n/(n+k)`), pas variance-based (SE non exposés) |
| **D-B** | On tire **les effets DPE** seulement (pas les élasticités) |
| **D-C** | Cible = cran **départemental** (EPCI plus tard, via `pooling_cible`) |
| **D-D** | Pré-calcul du **parent départemental** en tête de boucle, injecté au cran LOCAL |
| **D-E** | Erreur/palier mesurés sur le **fit local** (caveat assumé) |
| **D-F** | **Opt-in** `pooling_partiel_k=None` par défaut ; aucune persistance nouvelle |

Une fois ces six points tranchés, on ouvre l'issue GitHub dédiée et on part sur le lot 1
(logique pure `pooling.py`) en TDD.
