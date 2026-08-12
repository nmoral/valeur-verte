# Revue des stratégies de calcul — inventaire, décisions, protocole de test

Document de travail. Objectif : recenser **exhaustivement** toutes les opérations
et heuristiques qui produisent les valeurs du moteur, statuer sur chacune
(garder / ajuster / remplacer), et cadrer le protocole de test qui départagera
les variantes sur données réelles.

Contexte : l'audit de la base VPS (voir mémoire projet et
[`QUALITE-DONNEES.md`](QUALITE-DONNEES.md)) a montré un empilement d'heuristiques
et des résultats fragiles (57 % des mailles > 20 % d'erreur, R² négatifs,
appariement DPE sous le seuil). L'hypothèse de travail : **l'accumulation de
filtres et de seuils est elle-même une cause d'erreur silencieuse.**

> Aucune modification de code n'est engagée sans accord explicite. Ce document
> fige les décisions pour ne pas les reperdre entre deux sessions.

---

## 1. Décisions prises (synthèse)

| ID | Stratégie | Décision | Statut |
|---|---|---|---|
| **S10** | DPE multiples → étiquette retenue | **Prendre le DPE le plus récent** (au lieu du vote majoritaire) — reflète l'état après travaux | ✅ décidé, à implémenter |
| **S15** | Segment de biens | **Maisons uniquement** | ✅ validé (garder) |
| **S20** | Restriction bâti < 1989 | **Retirer la restriction** — un E récent existe et doit compter ; le supprimer est une erreur | ✅ décidé |
| **S12** | Bornes de nettoyage à l'ingestion | **Réduire à des garde-fous de bon sens** (prix > 0, surfaces plausibles) ; le tri fin passe à S32 | ✅ décidé (remplacé par S32) |
| **S23** | Pondération gaussienne σ | **σ adaptatif au rayon retenu** (piste B, σ ≈ rayon/3) — à mesurer | ✅ piste retenue, à mesurer |
| **S32** | Écrêtage de l'échantillon au calcul | **Nouvelle stratégie** remplaçant les percentiles départementaux — V1 `prix_m2`, V2 résidu pondéré | ✅ décidé, à mesurer |
| **S5** | Terrain « sols » seulement | **Diagnostic à faire** : pourquoi ~48 % de maisons sans terrain sur le 31 | 🔍 à diagnostiquer |
| **S17/S18/S21** | Trois seuils d'effectif | **Documenter la chaîne réelle** + protocole de test | 🔍 à cadrer |

Blocs restant à passer en revue avant décision : **régression** (S22, S24),
**sorties** (S29, S30), **agrégation DVF** (S1–S6).

---

## 2. Inventaire complet des stratégies

Ordre de traversée d'une valeur dans le pipeline. `fichier:ligne` cliquable.

### Bloc A — Construction de la donnée brute (une ligne par vente)

| ID | Stratégie | Fichier | Produit / utilité | Statut |
|---|---|---|---|---|
| S1 | Agrégation DVF lot → mutation (groupe par `id_mutation`) | `dvf.py:122` | Reconstitue une transaction unique à partir de lignes éclatées par lot | à revoir (bloc DVF) |
| S2 | Prix pris une seule fois (répété sur les lignes) | `dvf.py:108` | Évite de multiplier le prix par le nombre de lots | à revoir |
| S3 | Surface bâtie = somme des lots | `dvf.py:73` | Maison + dépendance = surface habitable totale | à revoir |
| S4 | Champs scalaires du lot de plus grande surface bâtie | `dvf.py:69` | Représente la mutation par son bien dominant | à revoir |
| S5 | Terrain = somme par parcelle distincte, nature « sols » (code `S`) | `dvf.py:89` | Additionne sans double compte, exclut les terres agricoles (corrige #136) | 🔍 diagnostic (48 % nul sur 31) |
| S6 | Prix au m² = prix / surface_bati | `dvf.py:116` | Normalisation, base de l'indice local (S24) et de l'écrêtage V1 (S32) | à revoir |
| S7 | Rattachement commune par `code_insee` (jamais code postal) | `dvf.py:141` | Clé stable ; un code postal couvre plusieurs communes | validé implicitement |
| S8 | Géocodage BAN, meilleur score | `ban.py:74` | Fournit lat/lon → socle du rayon (S17) et de l'indice local (S24) | à revoir |
| S9 | Clé d'adresse normalisée (accents, abréviations, mots vides) | `matching.py:82` | Rapproche deux orthographes DVF/ADEME, faute de clé commune | à revoir |
| S10 | Index DPE + choix de l'étiquette en cas de doublons | `dpe.py:29` | **DÉCIDÉ : dernier DPE** (voir §3) | ✅ décidé |
| S11 | Appariement DPE → étiquette + période | `dpe.py:58` | Carburant du modèle : sans étiquette ni période, la vente est écartée (S19) | à revoir |
| S12 | Bornes de nettoyage fixes (prix 30k–900k, bâti 50–250, terrain 50–5000) | `cleaning.py:16` | **DÉCIDÉ : réduire aux garde-fous** (voir §3, remplacé par S32) | ✅ décidé |
| S13 | Remap COG (`remap_insee`) | `cleaning.py:46` | Fusions/scissions de communes ne cassent pas le rattachement | validé implicitement |
| S14 | Écriture idempotente (delete puis insert par commune+millésime) | `ingest.py:30` | Un ré-import ne crée pas de doublons (pas de contrainte d'unicité) | validé implicitement |

### Bloc B — Sélection de l'échantillon (par commune à calculer)

| ID | Stratégie | Fichier | Produit / utilité | Statut |
|---|---|---|---|---|
| S15 | Filtre segment `type_local = "Maison"` | `pool_repository.py:23` | **VALIDÉ (garder)** — sur les appartements l'emplacement écrase le DPE (#115) | ✅ garder |
| S16 | Débordement sur départements limitrophes | `limitrophes.py:23` | Une commune de bord voit un voisinage entier, pas un demi-disque (#18) | validé (#140) |
| S17 | Rayon adaptatif 10→15→20→30→50 km jusqu'à `min_sample=80` | `radius.py:41` | Assez de ventes pour régresser, en restant le plus local possible | 🔍 seuils à cadrer |
| S18 | Décision de mode (LOCAL ≥30, sinon DÉPARTEMENTAL, sinon REFUS) | `degraded.py:39` | Estimation prudente plutôt que rien, signalée par un bandeau | 🔍 seuils à cadrer |

### Bloc C — Éligibilité et régression (par maille)

| ID | Stratégie | Fichier | Produit / utilité | Statut |
|---|---|---|---|---|
| S19 | Filtre d'éligibilité (prix>0, bâti>0, DPE présent, coords présentes) | `regression.py:86` | Champs requis par le modèle ; log(prix) exige prix>0 | à revoir |
| S20 | Restriction bâti ancien < 1989 | `regression.py:71` | **DÉCIDÉ : retirer** (voir §3) | ✅ décidé |
| S21 | Classes DPE publiables (≥15 par classe, ≥2 classes) | `batch.py:66` | Ne pas publier un coefficient extrapolé sur une poignée de ventes | 🔍 seuils à cadrer |
| S22 | Modèle log-linéaire pondéré (log bâti, log1p terrain, indice local, dummies DPE/période, EF commune/année) | `regression.py:235` | Isole l'effet propre du DPE, toutes choses égales par ailleurs | à revoir (sur-paramétrage ?) |
| S23 | Pondération gaussienne σ = 10 km | `regression.py:66` | **DÉCIDÉ : σ adaptatif** (voir §3) | ✅ piste, à mesurer |
| S24 | Indice local = médiane prix/m² des 20 plus proches voisines (bien exclu) | `regression.py:183` | Capte le micro-quartier sans auto-prédiction | à revoir (chevauche S23) |
| S25 | Indicatrices période de construction | `regression.py:148` | Sans elles, l'étiquette DPE capterait l'effet de l'époque (#115) | à revoir |
| S26 | Extraction coefficients (DPE en log, bâti/terrain = élasticités) | `regression.py:274` | Valeurs stockées. ⚠️ Grandeurs **relatives (log/%)**, pas des euros | à revoir (unité) |
| S27 | R² pondéré sur l'échelle log | `regression.py:278` | Qualité d'ajustement interne (INV-4) | à revoir |
| S28 | Erreur médiane par validation croisée (5 plis déterministes, % médian) | `cross_validation.py:27` | Justesse prédictive défendable ; déterministe = reproductible (INV-5) | à revoir |
| S29 | IC empirique par classe : `coef ± |coef|·(err_médiane/100)` | `batch.py:72` | Fourchette calibrée sur l'erreur réelle de la maille | à revoir (heuristique) |

### Bloc D — Écriture et orchestration

| ID | Stratégie | Fichier | Produit / utilité | Statut |
|---|---|---|---|---|
| S30 | Upsert `coefficient_maille` (PK `code_insee`), refus → suppression | `cli.py:172` | Requête widget = simple SELECT indexé. ⚠️ Pas de millésime dans la PK → pas d'historique | à revoir (bloque REG-1) |
| S31 | Couverture progressive (en_attente → charge → calcule ; tous limitrophes chargés) | `coverage.py:26` | Évite artefacts de bord et hors-zone incohérent | validé implicitement |

### Nouvelle stratégie

| ID | Stratégie | Produit / utilité | Statut |
|---|---|---|---|
| **S32** | **Écrêtage de l'échantillon au calcul** (troncature robuste) | Retire les comparables les plus atypiques *de l'échantillon réellement utilisé*, quand n est suffisant | ✅ décidé, à mesurer |

---

## 3. Détail des solutions retenues

### S10 — Dernier DPE au lieu du vote majoritaire
Le jeu ADEME porte une date d'établissement (`date_etablissement_dpe`), non lue
aujourd'hui (`dpe_source.py:26` ne récupère que 4 champs). Changement localisé :
1. Ajouter `date_etablissement_dpe` au `_FIELD_MAP` et au `select`.
2. Dans `build_dpe_lookup` (`dpe.py:29`), remplacer `Counter.most_common` par
   « garder l'enregistrement de date maximale » (étiquette **et** période prises
   sur ce même DPE).
3. Confirmer le nom exact du champ sur l'API réelle via
   `tests/integration/test_dpe_source_reel.py`.

**Pourquoi** : après travaux (E → C), le vote majoritaire peut renvoyer le E
historique et sous-estimer un bien rénové — l'inverse de ce que le produit mesure.

### S12 — Réduire aux garde-fous (le tri fin passe à S32)
Constat : les bornes fixes **censurent le haut de gamme légitime** — mur à
exactement 250 m² dans tous les départements, mur à 900 k€ sur le 31 (P99 = 714 k).
Le nettoyage à l'ingestion (`ingest.py:26`) ne garde que des garde-fous de bon
sens (prix > 0, surfaces plausibles) ; la suppression des atypiques descend au
calcul, sur l'échantillon poolé, via **S32**.

Écarté : percentiles **départementaux** (dominés par Toulouse) et percentiles
**locaux** (instables sur petites communes). Le bon grain est « les comparables
réellement utilisés », c'est-à-dire l'échantillon du rayon → S32.

### S20 — Retirer la restriction bâti < 1989
Le produit veut mesurer l'impact d'un passage E → C. La date de construction est
marquante mais des logements **récents en E** existent ; les supprimer biaise le
signal. On retire le filtre (`is_pre_1989`, `regression.py:71`) — élargissement
majeur du pool. La période de construction reste dans le modèle comme variable
(S25) pour contrôler l'effet d'époque.

### S23 — σ adaptatif au rayon retenu (piste B)
Avec σ = 10 km, une maison à 5 km pèse 0,88 (quasi autant qu'une voisine) : la
pondération ne localise pas avant 10 km. Baisser σ globalement affamerait les
mailles rurales (échantillon tiré jusqu'à 50 km). Solution : **σ proportionnel au
rayon réellement retenu** (p. ex. σ = rayon/3). Urbain dense (rayon 10 → σ ≈ 3,3 km,
5 km pèse 0,33) ; rural (rayon 50 → σ ≈ 17 km, lâche mais stable). La finesse
s'adapte à la densité de données. À départager par la mesure (A : σ fixe petit ;
B : σ adaptatif).

Poids gaussien : `w(d) = exp(−d² / 2σ²)`.

| d | 0,5 km | 2 km | 5 km | 10 km | 20 km | 30 km |
|---|---|---|---|---|---|---|
| σ=10 | 1,00 | 0,98 | 0,88 | 0,61 | 0,14 | 0,01 |

### S32 — Écrêtage de l'échantillon au calcul (troncature robuste)
Sur l'échantillon du rayon (S17), retirer les comparables les plus atypiques,
**seulement si n ≥ 100** (gate) pour ne jamais déstabiliser un petit échantillon.
Faible impact sur la taille, potentiellement décisif sur les coefficients (vise
les points à fort levier, responsables présumés des R² négatifs).

**V1 — sur `prix_m2` (simple, sans modèle, explicable au client)**
Écrêter top/bottom ~1 % du prix au m². Surtout **pas sur le prix brut** (cela
recouperait les grandes maisons chères, le biais de S12 qu'on fuit).

**V2 — sur le résidu pondéré (fin, post-modèle)**
1. Premier ajustement sur l'échantillon éligible complet → `beta`.
2. Résidu en log : `r_i = log(prix_i) − (design_i · beta)` (sans unité, insensible
   au niveau de prix ; le code calcule déjà `y − predictions`, `regression.py:278`).
3. Échelle robuste **MAD** : `s = 1,4826 · médiane(|r_i − médiane(r)|)`, `z_i = r_i/s`
   (MAD et non écart-type, pour éviter le masquage par les outliers eux-mêmes).
4. Écrêter sur le **résidu pondéré** `√(w_i) · r_i` — cible le point mal ajusté
   **ET** influent (proche), pas le lointain déjà à poids nul. Seuil `|z| > k`
   (k ≈ 3–3,5), adaptatif : ne coupe rien sur une maille saine.
5. Ré-ajustement sur l'échantillon écrêté → coefficients finaux (une passe ;
   itérer converge vers la Least Trimmed Squares, rarement nécessaire).

**Nuances d'intégration**
- Validation croisée (S28) : écrêter **seulement l'apprentissage** de chaque pli,
  évaluer sur les tests **non écrêtés** (entraîner robuste, mesurer honnête).
- Déterminisme (INV-5) : médiane/MAD/tri déterministes → OK.
- Diagnostic gratuit : si l'écrêtage retire beaucoup ou fait fortement bouger les
  coefficients, c'est un signe de **modèle mal spécifié**, pas d'outliers. La
  quantité écrêtée devient un indicateur de santé de la maille.

| | Écrête sur | Coût | Attrape |
|---|---|---|---|
| V1 | `prix_m2` (brut) | 1 ajustement | trop cher / trop bradé *en absolu* |
| V2 | résidu pondéré | 2 ajustements | anomalie *conditionnelle* (prix normal mais aberrant vu ses caractéristiques) |

---

## 4. Protocole de test

**Principe** : tests **en local** sur le dump complet, une hypothèse à la fois,
en commençant par ce qui bouge le plus le **pool de calcul** (impact massif), puis
les **paramètres de calcul**. On mesure sur plusieurs communes de profils variés,
on compare, et on **archive un dump identifié par expérience** pour réutilisation
et pour **mixer les solutions** ensuite.

### 4.1 Base locale
Restaurer le dump complet (`dumps/valeur_verte_2026-08-11_complet.dump`) dans une
base isolée **`vv_lab`** (la base de dev courante n'a qu'une vente). Point de
départ figé et reproductible.

### 4.2 Communes-test (profils variés)
À fixer sur de vraies communes du dump — cibles :
- **urbain dense** : Toulouse `31555`
- **périurbain** : à choisir (Colomiers / Muret…)
- **rural 31** : petite commune
- **rural autre département** : Ariège `09…`
- **maille en repli départemental** : petit n

*(à figer en lecture seule avant le premier run.)*

### 4.3 Métriques comparées, par commune et par run
`n_transactions`, `rayon`, `mode`, `r2`, `erreur_mediane`, `coef_m2_bati`,
`coef_m2_terrain`, et surtout le **gain E → C en %** (`exp(coef_C − coef_E) − 1`),
la valeur produit. Bonus S32 : **nombre de biens écrêtés** par maille.

### 4.4 Archivage
Un dump par expérience, nommé explicitement, ex. :
`vv_lab_S20_sans_restriction_1989.dump`, `vv_lab_S32_v2_residu.dump`. Conserver
pour réutilisation et combinaison ultérieure.

### 4.5 Bascule des stratégies (drapeaux)
Chaque hypothèse « pool » exige un **drapeau** pour l'activer/désactiver sans
casser le code existant (seule écriture de code du protocole, minimale) :
- S20 : restriction `is_pre_1989` codée en dur dans `regression._eligible`.
- S12 : constantes dans `cleaning`.
- S32 : nouvelle passe, activable par variante (V1 / V2 / off).
- S23 : `DEFAULT_SIGMA_KM` → formule adaptative optionnelle.

### 4.6 Ordre d'exécution
1. **Bloc pool** (impact massif) : **S20** (retrait < 1989) → **S12/S32** (garde-fous
   + écrêtage) → puis mixage.
2. **Bloc paramètres de calcul** : **S23** (σ) → **S24** (indice local) → **S22**
   (sur-paramétrage : EF commune + indice + gaussienne) → S29 (IC).

---

## 5. Points ouverts (à trancher plus tard)

- **S5** — diagnostic « pourquoi 48 % de maisons sans terrain sur le 31 » (filtre
  `S` trop strict ? trou de données ?). Impact direct sur `coef_m2_terrain` et les
  25 mailles à terrain ≤ 0.
- **S17 / S18 / S21** — documenter la chaîne des trois seuils (80 sur le pool brut,
  30 sur l'éligible, 15 par classe) et vérifier qu'aucune combinaison n'est absurde
  (ex. 80 dans le rayon mais 25 éligibles → bascule départementale silencieuse).
- **S22 / S24 / S23** — sur-paramétrage possible : effets fixes commune **+** indice
  local **+** pondération gaussienne = trois mécanismes de localité empilés. Piste
  des R² négatifs.
- **S26** — unité des sorties (log/élasticité vs seuils d'invariants en euros) :
  exprimer en **pourcentage** (`exp(coef) − 1`). Voir revue des invariants.
- **S29** — l'IC empirique `coef ± |coef|·err` est une heuristique, à confronter à
  un IC statistique.
- **S30** — pas d'historique des coefficients (PK `code_insee` seule) → REG-1
  (#129) impossible en l'état ; et effectifs par classe non persistés → tolérance
  INV-1 (#119) incalculable.
