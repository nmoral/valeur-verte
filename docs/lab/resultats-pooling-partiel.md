# Résultats — Pooling partiel hiérarchique (#249, lot 4 : calibration VPS)

> **Statut : série multi-millésime 2022→2025 acquise** (job `pooling-millesimes.sh`, VPS,
> 2026-08-19). Restent à mesurer sur 2025 les garde-fous **off vs on** (biais sur mailles
> précises, erreur CV, monotonie INV-1) et la **calibration du seuil du badge #261** (§5,
> bloquée par le déploiement). Livrable du **lot 4** de la note de conception
> [`conception-pooling-partiel.md`](conception-pooling-partiel.md) (§9 protocole, §10 lot 4) :
> décider **défaut vs opt-in** de `pooling_hierarchique`.

## 0. Question tranchée par ce document

Le pooling hiérarchique (empirical-Bayes entre communes, §4ter du référentiel) est
aujourd'hui **opt-in** (`pooling_hierarchique=False`, `VV_MOTEUR_POOLING_HIERARCHIQUE`).
On bascule le **défaut à `on`** **si et seulement si** les gains attendus sont nets
**sans** régression sur les garde-fous :

| Critère (protocole §9) | Sens attendu | Seuil de décision |
|---|---|---|
| (1) Stabilité rurale — variance / queue p90 du premium E→C | ↓ | net |
| (2) Biais / atténuation — premium des mailles **précises** | ~ inchangé | garde-fou (ne pas moyenniser une commune vraiment plus verte) |
| (3) Taux de publication au LOCAL | ↑ | net (gain principal) |
| (4) Erreur médiane CV | ne pas se dégrader | garde-fou |
| (5) Monotonie INV-1 | ↑ | net |
| (6) Diagnostic — distribution des `τ̂²` / poids `w` | cohérence métier | qualitatif |

**Bascule si (1)+(3)+(5) nets ET (2)+(4) non dégradés ; sinon garder opt-in** (comme τ #208).

---

## 1. Méthode

- **Données** : base France métropole 2021-2025 en prod (import France entière, #223),
  exclusion multi-communes #252 active partout.
- **Moteur** : fenêtre **W2** (défaut), pooling **off** (baseline) vs **on**. Config
  identique par ailleurs. Compute-only, archive `coefficient_maille_historique` (REG-2).
- **Comparaison off vs on** : baseline figée `data.hist_baseline_mc` (état AVANT :
  pré-#252 / pré-pooling) vs archive avec pooling. ⚠️ La baseline cumule **deux**
  changements (#252 + pooling) ; l'effet **propre** du pooling est mesuré sur le
  **sous-ensemble de communes communes** aux deux états (voir §2).
- **Série multi-millésime** : millésimes **2022, 2023, 2024, 2025** recalculés **tous en
  W2 + pooling** (`pooling_m_<N>.csv`), pour la dérive inter-année et la robustesse de la
  décision. 2021 écarté (W2 dégénère en W1 faute de 2020).

---

## 2. Effet propre du pooling — N=2025, France entière (ACQUIS)

Mesuré sur les **communes communes** baseline↔pooling (isole le pooling du filtre
multi-communes).

**Granularité — taux de publication au LOCAL (critère 3)** :

| Métrique | Sans pooling | Avec pooling | Δ |
|---|---|---|---|
| Mailles promues EPCI/DÉPT → **LOCAL** | — | **+3 043** | ~+9,3 % du parc |
| Mailles rétrogradées | — | 50 | négligeable |
| Part servie au cran **LOCAL** | ~50 % | **~60 %** | **+10 pts** |

→ **Critère (3) : NET.** Le shrinkage régularise assez de petites mailles bruitées pour
qu'elles passent les invariants au cran LOCAL au lieu de basculer EPCI/DÉPT.

**Réduction de variance — gain E→C log (critère 1)**, sur 26 697 mailles E&C communes :

| Métrique | Sans pooling | Avec pooling | Δ |
|---|---|---|---|
| Écart-type du gain E→C (log) | 0,0835 | 0,0664 | **−20 %** |
| Étendue p10–p90 du gain E→C (%) | [12,2 ; 38,3] | [13,3 ; 32,7] | **−26 %** |
| Médiane du gain E→C (%) | 23,2 | 22,6 | **stable** (pas de biais) |
| Gains E→C négatifs (INV-2) | 0 | 0 | tenu |

→ **Critère (1) : NET** (variance et queue ↓), **et critère (2) partiellement rassuré**
(médiane stable = pas d'atténuation moyenne du signal). Reste à confirmer (2) **sur les
mailles à forte précision** spécifiquement (w→1 doit les laisser ~inchangées).

**Critères encore à mesurer sur 2025** :
- (2) atténuation ciblée sur les mailles précises (w proche de 1) — ⏳
- (4) erreur médiane CV off vs on — ⏳
- (5) taux de monotonie INV-1 off vs on — ⏳
- (6) distribution des `τ̂²` / `w` — ⏳ (dépend de `poids_pooling`, voir §5)

---

## 3. Série multi-millésime 2022→2025

Tous les millésimes en **W2 + pooling**. Objectif : la décision ne doit pas dépendre d'un
millésime particulier, et la **dérive inter-année** du premium doit rester lisible sous
pooling (le pooling régularise entre communes, pas dans le temps — cf. caveat §11 conception).

**3.1 Premium & stabilité par millésime** (mailles B/C/E fiables, eff≥20) :

| Millésime | n mailles | E→C médian | C→B médian | E→B médian | écart-type E→C | part LOCAL |
|---|---|---|---|---|---|---|
| 2022 | 4 827 | +9,5 % | +0,9 % | +11,1 % | 3,7 pts | 29,6 % |
| 2023 | 7 635 | +14,3 % | +2,9 % | +18,6 % | 5,5 pts | 49,8 % |
| 2024 | 9 146 | +18,6 % | +4,8 % | +23,6 % | 6,6 pts | 55,6 % |
| 2025 | 11 666 | +21,1 % | +5,5 % | +27,5 % | 7,3 pts | 59,2 % |

⚠️ **Lecture** : la **couverture** monte fortement 2022→2025 (part LOCAL 30 %→59 %, n mailles
B/C/E ×2,4). Ce n'est **pas** un effet du pooling mais de l'**appariement DPE** : le stock de
diagnostics s'étoffe avec le temps → les millésimes récents ont plus de ventes éligibles. Les
années **2024-2025 sont la base robuste** ; 2022 (couverture mince, 51 dépts en §4.2) donne la
même **direction** mais sur peu de données.

**3.2 Dérive inter-année sur panel stable** (1 467 mailles B/C/E fiables sur **les 4** millésimes) :

| Millésime | E→C médian | C→B médian | E→B médian | ratio E→C/C→B |
|---|---|---|---|---|
| 2022 | +9,4 % | +1,6 % | +11,8 % | 5,9× |
| 2023 | +14,6 % | +3,4 % | +19,4 % | 4,3× |
| 2024 | +18,1 % | +4,9 % | +24,0 % | 3,7× |
| 2025 | +19,1 % | +6,9 % | +26,4 % | 2,8× |

- **Dérive E→C 2022→2025 : +103 %** (9,4 %→19,1 %, ~+27 %/an) — le premium **double en 3 ans**.
- **Dérive C→B : +333 %** (1,6 %→6,9 %) — le dernier cran vers B croît **plus vite** (en relatif) que E→C.
- **Le pooling n'aplatit PAS la dérive temporelle** — comportement voulu : il régularise **entre
  communes**, pas **dans le temps** (§11 conception). La dérive est du **signal marché réel**.
  Caveat W2 : à poids égaux la fenêtre introduit un léger **retard** (~−6 %, cf. #208/#177) → le
  premium **courant réel est un peu au-dessus** de ces médianes.

---

## 4. Orientation « C ou B ? » — solidité du B sous pooling

Contexte : la vérification C/B pré-pooling (millésime 2025, sans pooling) a établi que
**le gros du gain est atteint à C, le dernier cran vers B rapporte ~4× moins, partout**
(E→C médian +21,4 %, C→B +5,8 %, 86/86 dépts). Ce lot **rejoue ce constat sous pooling
et sur plusieurs millésimes** — le message publiable doit tenir sur la donnée régularisée.

**4.1 Solidité du B (couverture / précision)** :

| Millésime | % mailles B publiable | dépts avec B | effectif B médian | SE.B médian | SE.C médian |
|---|---|---|---|---|---|
| 2022 | 23,8 % | 79 | 52 | 0,047 | 0,042 |
| 2023 | 26,9 % | 83 | 51 | 0,043 | 0,043 |
| 2024 | 30,5 % | 87 | 51 | 0,041 | 0,043 |
| 2025 | 38,0 % | 88 | 50 | 0,040 | 0,042 |

Le B est **bien couvert et de plus en plus** (24 %→38 % des mailles, 88 dépts en 2025), effectif
médian stable (~50 ventes), SE serré et stable (~0,04). Le « doute sérieux » sur la fiabilité du B
ne tient pas — cohérent avec la vérification pré-pooling.

**4.2 Universalité de l'écart C/B** (dépts avec ≥20 mailles B/C/E) :

| Millésime | dépts | C→B < E→C | C→B > 0 |
|---|---|---|---|
| 2022 | 51 | 48/51 | 44/51 |
| 2023 | 71 | 71/71 | 59/71 |
| 2024 | 77 | 75/77 | 75/77 |
| 2025 | 86 | **86/86** | **86/86** |

→ **Le message « C ou B ? » tient — et robustement sur les années récentes.** En 2025, **86/86**
dépts ont C→B < E→C **et** C→B > 0 (le B se paie, mais bien moins que la montée à C, **partout**).
Le ratio E→C/C→B vaut **3,8×** en 2025 (par-dept) — **cohérent avec le pré-pooling** (E→C +21,4 %,
C→B +5,8 %, ratio ~3,7×) : **le pooling ne distord pas l'économie C/B**.

**Nuance importante** : le ratio se **resserre dans le temps** (11× en 2022 → 3,8× en 2025 ; panel
stable 5,9×→2,8×) — le B **rattrape**. « Le dernier cran rapporte ~4× moins » est un énoncé **de
l'ère récente** (2024-2025) ; il était ~10× en 2022. Le message reste vrai aujourd'hui, mais l'écart
C/B se referme au fil des millésimes — à ne pas figer comme une constante.

---

## 5. Calibration du seuil du badge #261 (⏳ — dépend du déploiement)

Le badge « partiellement rapprochée du département » s'affiche quand
`poids_pooling < SEUIL_RAPPROCHEMENT` (constante **0,5** en dur, jamais calibrée). Le seuil
doit se caler sur la **distribution réelle** des `w` (min des poids par maille) : combien de
mailles porteraient le badge à 0,3 / 0,5 / 0,7 ?

⚠️ **Bloqué par un déploiement** : l'image data en prod (`395da292`) est **antérieure** au
lot data #261 (PR #274) — elle **ne produit pas `poids_pooling`**, et la migration 019
(colonne) n'est pas appliquée. Le job multi-millésime en cours tourne avec cette image :
il **ne fournit donc pas** la distribution des `w`. Cette calibration attend un recompute
avec l'image à jour (post-job, tâche « B » de déploiement #261).

| Seuil candidat | % mailles badgées (2025) | Interprétation |
|---|---|---|
| 0,3 | ⏳ | badge réservé au shrinkage très fort |
| 0,5 (actuel) | ⏳ | — |
| 0,7 | ⏳ | badge large |

---

## 6. Décision (état)

- [x] (1) stabilité rurale nette — **acquis** (var E→C −20 %, p90 −26 % sur 2025) ; la série confirme
  que la médiane n'est **pas** atténuée (dérive marché préservée, §3.2).
- [ ] (2) pas d'atténuation des mailles précises — **à mesurer** (off vs on, w→1 ⇒ inchangé) — dernier garde-fou ouvert.
- [x] (3) publication LOCAL nette — **acquis** (+10 pts / +3 043 mailles sur 2025 ; part LOCAL 59 % en 2025, §3.1).
- [ ] (4) erreur CV non dégradée — **à mesurer** (off vs on).
- [ ] (5) monotonie INV-1 ↑ — **à mesurer** (off vs on).
- [ ] (6) diagnostic `τ̂²`/`w` cohérent — **bloqué** (dépend de `poids_pooling`, §5, déploiement #261).

**Verdict provisoire** : les deux gains **principaux** — stabilité rurale (1) et publication LOCAL (3)
— sont **acquis et nets**, et la série 2022→2025 montre que le pooling **ne dénature pas** le signal
(dérive du premium préservée, économie C/B intacte : ratio poolé 3,8× ≈ non poolé 3,7×, **86/86**
dépts en 2025). La **bascule du défaut** reste suspendue aux garde-fous **off vs on** (2)+(4)+(5),
qui exigent un run **baseline sans pooling** au même périmètre (à enchaîner), et au déploiement #261
pour (6). **Recommandation intermédiaire** : garder **opt-in** jusqu'à (2)+(4)+(5) mesurés ; rien
dans la série ne s'y oppose.

**Retombée produit immédiate (indépendante de la bascule)** : la série **valide et affine** le
message « C ou B ? » de la page publique — universel et robuste en 2024-2025, avec la **nuance
neuve** que l'écart C/B se **referme** dans le temps (à ne pas figer comme « ~4× » ad vitam).

---

*Provenance des chiffres 2025 : run `recompute-pooling.sh` du 2026-08-18, analyse
baseline `hist_baseline_mc` vs archive pooling (recompute multi-communes #252).*
